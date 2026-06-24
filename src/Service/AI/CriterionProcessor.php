<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\Criterion;
use App\Service\Document\PdfTextExtractor;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Shared interpretive-criterion pipeline: PDF bytes → clean text (+ summary /
 * keypoints) → chunked embeddings in the `ai_ctbg_criteria` Postgres store.
 *
 * This is the single source of truth for the two stages that previously lived
 * inline in {@see \App\Command\ImportCriteriaPdfsCommand} (extract + enrich)
 * and {@see \App\Command\LoadCGBGCriteriaCommand} (vectorise). Those commands
 * now delegate here, and so does the async {@see \App\MessageHandler\ProcessCriterionHandler}
 * dispatched when a criterion PDF is uploaded from the admin — so the web,
 * CLI and queue paths stay byte-for-byte identical.
 */
final class CriterionProcessor
{
    private const MIN_USABLE_CHARS = 500;

    /**
     * Mirrors `table_name` for the `ctbg_criteria` store in
     * config/packages/ai_postgres_store.yaml. Used to purge a criterion's stale
     * chunks before re-vectorising (the store API only deletes by row id, and
     * our chunk ids are random, so we delete by the `criterionId` metadata key).
     */
    private const STORE_TABLE = 'ai_ctbg_criteria';

    private ?bool $tesseractAvailable = null;
    private bool $storeReady = false;

    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly CriterionEnricher $criterionEnricher,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.ctbg_criteria')]
        private readonly StoreInterface $ctbgCriteriaStore,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Stage A — turn the raw PDF into clean `fullText` and, when $useLlm is on,
     * a distilled `summary` + `keypoints`. Mirrors the per-file logic of
     * {@see \App\Command\ImportCriteriaPdfsCommand::execute()}.
     *
     * The entity is mutated in place; persistence is the caller's job.
     */
    public function extractAndEnrich(Criterion $criterion, string $pdfBytes, bool $useLlm = true): void
    {
        if ($pdfBytes === '') {
            $this->logger->warning('Criterion PDF is empty, nothing to extract', [
                'criterion' => $criterion->getReferenceNumber(),
            ]);

            return;
        }

        $cleaned = null;

        // Preferred path: vision-LLM transcription (best for low-quality e-signed scans).
        if ($useLlm) {
            $llmCleaned = $this->criterionEnricher->cleanTextFromPdf($pdfBytes);
            if ($llmCleaned !== null && mb_strlen($llmCleaned) >= self::MIN_USABLE_CHARS) {
                $cleaned = $llmCleaned;
            }
        }

        // Fallback: pdftotext + repeated-line strip, then OCR if still too short.
        if ($cleaned === null) {
            $rawText = $this->pdfTextExtractor->extractFullTextFromContent($pdfBytes);
            $cleaned = $this->pdfTextExtractor->removeRepeatedLines($rawText);

            if (mb_strlen($cleaned) < self::MIN_USABLE_CHARS) {
                $ocrText = $this->maybeOcrFromContent($pdfBytes);
                if ($ocrText !== null && mb_strlen($ocrText) >= self::MIN_USABLE_CHARS) {
                    $cleaned = $this->pdfTextExtractor->removeRepeatedLines($ocrText);
                }
            }
        }

        if (trim($cleaned) === '') {
            $this->logger->warning('Criterion text extraction produced empty text', [
                'criterion' => $criterion->getReferenceNumber(),
            ]);

            return;
        }

        $criterion->setFullText($cleaned);

        // Distil summary + keypoints (used by the cheap screening stage and for
        // display). Only with the LLM path, to avoid surprise model calls.
        if ($useLlm) {
            $enrichment = $this->criterionEnricher->extractSummaryAndKeypoints($cleaned);
            $criterion->setSummary($enrichment['summary']);
            $criterion->setKeypoints($enrichment['keypoints'] !== [] ? $enrichment['keypoints'] : null);
        }
    }

    /**
     * Stage B — chunk `fullText`, embed each chunk and (re)write the criterion's
     * documents into the Postgres vector store. Mirrors the per-criterion loop
     * of {@see \App\Command\LoadCGBGCriteriaCommand::execute()}.
     *
     * Idempotent: any previous chunks for this criterion are purged first, so
     * re-uploading or editing a criterion never leaves stale embeddings behind.
     */
    public function vectorize(Criterion $criterion): void
    {
        $fullText = $criterion->getFullText();
        if (trim($fullText) === '') {
            $this->logger->warning('Criterion has no fullText to vectorise', [
                'criterion' => $criterion->getReferenceNumber(),
            ]);

            return;
        }

        $this->ensureStore();
        $this->removeVectors($criterion);

        $chunks = $this->pdfTextExtractor->chunkText($fullText);
        if ($chunks === []) {
            return;
        }

        $documents = [];
        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->embeddingGenerator->generate($chunk['text']);
            } catch (\Exception $e) {
                $this->logger->error('Error generating embedding for criterion chunk', [
                    'criterion' => $criterion->getReferenceNumber(),
                    'chunkIndex' => $chunk['chunkIndex'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $documents[] = new VectorDocument(
                id: (string) Uuid::v7(),
                vector: new Vector($embedding),
                metadata: new Metadata([
                    Metadata::KEY_TEXT => $chunk['text'],
                    Metadata::KEY_SOURCE => $criterion->getSource(),
                    'criterionId' => (string) $criterion->getId(),
                    'criterion' => $criterion->getReferenceNumber(),
                    'year' => $criterion->getYear(),
                    'topic' => $criterion->getTopic(),
                    'scope' => $criterion->getScope(),
                    'sourceUrl' => $criterion->getSourceUrl(),
                    'chunkIndex' => $chunk['chunkIndex'],
                ]),
            );
        }

        if ($documents !== []) {
            $this->ctbgCriteriaStore->add($documents);
            $this->logger->info('Criterion vectorised', [
                'criterion' => $criterion->getReferenceNumber(),
                'chunks' => count($documents),
            ]);
        }
    }

    /**
     * Create/verify the vector collection. Same setup options the load command
     * uses; CREATE TABLE IF NOT EXISTS makes it idempotent and cheap.
     */
    public function ensureStore(): void
    {
        if ($this->storeReady || !$this->ctbgCriteriaStore instanceof ManagedStoreInterface) {
            return;
        }

        $this->ctbgCriteriaStore->setup([
            'vector_type' => 'halfvec',
            'vector_size' => 3072,
            'index_method' => 'hnsw',
            'index_opclass' => 'halfvec_cosine_ops',
        ]);

        $this->storeReady = true;
    }

    /**
     * Delete all stored chunks for a criterion by the `criterionId` metadata
     * key. No-op (zero rows) the first time a criterion is vectorised, and the
     * cleanup hook when a criterion is deleted from the admin.
     */
    public function removeVectors(Criterion $criterion): void
    {
        try {
            $this->connection->executeStatement(
                \sprintf("DELETE FROM %s WHERE metadata->>'criterionId' = :id", self::STORE_TABLE),
                ['id' => (string) $criterion->getId()],
            );
        } catch (\Throwable $e) {
            // Table may not exist yet on a fresh install — ensureStore() handles
            // creation, and a missing table simply means nothing to purge.
            $this->logger->debug('Could not purge previous criterion chunks', [
                'criterion' => $criterion->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * OCR a PDF given its raw bytes: write to a temp file and run the
     * `pdftoppm | tesseract` pipeline. Returns null if tesseract isn't on PATH
     * or the run yields nothing.
     */
    private function maybeOcrFromContent(string $pdfBytes): ?string
    {
        if (!$this->isTesseractAvailable()) {
            return null;
        }

        $tmpPdf = tempnam(sys_get_temp_dir(), 'criterion-ocr-') . '.pdf';
        if (file_put_contents($tmpPdf, $pdfBytes) === false) {
            return null;
        }

        try {
            return $this->maybeOcr($tmpPdf);
        } finally {
            @unlink($tmpPdf);
        }
    }

    private function maybeOcr(string $pdfPath): ?string
    {
        $tmpDir = sys_get_temp_dir() . '/pideinfo-ocr-' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0o700, true)) {
            return null;
        }

        try {
            // Render every page to PNG at 300 dpi (the sweet spot for tesseract
            // on signed-PDF body text), then OCR each page.
            $rasterise = new Process(['pdftoppm', '-r', '300', '-png', $pdfPath, $tmpDir . '/p']);
            $rasterise->setTimeout(120);
            $rasterise->mustRun();

            $text = '';
            foreach (glob($tmpDir . '/p-*.png') ?: [] as $png) {
                $ocr = new Process(['tesseract', '-l', 'spa', $png, '-']);
                $ocr->setTimeout(60);
                try {
                    $ocr->mustRun();
                    $text .= $ocr->getOutput() . "\n\n";
                } catch (ProcessFailedException) {
                    // Skip pages tesseract can't decode; partial text is still useful.
                }
            }

            return trim($text) === '' ? null : $text;
        } catch (ProcessFailedException $e) {
            $this->logger->warning('Criterion OCR failed', [
                'pdf' => basename($pdfPath),
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    private function isTesseractAvailable(): bool
    {
        if ($this->tesseractAvailable !== null) {
            return $this->tesseractAvailable;
        }

        return $this->tesseractAvailable = (new ExecutableFinder())->find('tesseract') !== null;
    }
}
