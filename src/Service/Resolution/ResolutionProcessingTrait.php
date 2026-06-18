<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Service\Document\PdfOcrTranscriber;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Shared processing methods for resolution import commands (CTBG, GAIP).
 *
 * Commands using this trait must provide:
 * - $this->httpClient (HttpClientInterface)
 * - $this->resolutionsStorage (FilesystemOperator)
 * - $this->analyzer (ResolutionAnalyzer)
 * - $this->dateExtractor (ResolutionDateExtractor)
 * - $this->embeddingGenerator (EmbeddingGenerator)
 * - $this->vectorStore (StoreInterface)
 * - $this->logger (LoggerInterface)
 */
trait ResolutionProcessingTrait
{
    private const MAX_CHUNK_CHARS = 4000;

    private PdfOcrTranscriber $pdfOcrTranscriber;

    /**
     * Injected via setter so consuming commands don't each need to declare the
     * dependency in their constructor. Autoconfigure (enabled in services.yaml)
     * calls every #[Required] method on the command service.
     */
    #[Required]
    public function setPdfOcrTranscriber(PdfOcrTranscriber $pdfOcrTranscriber): void
    {
        $this->pdfOcrTranscriber = $pdfOcrTranscriber;
    }

    /**
     * Backfill PDF extraction + AI analysis + vectorization for resolutions that have a sourceUrl
     * but no extracted fullText. Dispatches async messages when $async is true, otherwise processes inline.
     *
     * Requires the consuming command to provide:
     * - $this->resolutionRepository (ResolutionRepository)
     * - $this->messageBus (MessageBusInterface)
     * - $this->entityManager (EntityManagerInterface)
     */
    private function processMissingPdfs(
        string $source,
        bool $async,
        bool $skipAnalysis,
        bool $skipVectors,
        ?int $limit,
        SymfonyStyle $io,
    ): int {
        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.source = :source')
            ->andWhere('r.sourceUrl IS NOT NULL')
            ->andWhere('(r.fullText IS NULL OR r.fullText = :empty)')
            ->setParameter('source', $source)
            ->setParameter('empty', '')
            ->orderBy('r.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $ids = array_column($qb->getQuery()->getArrayResult(), 'id');
        $count = count($ids);

        if ($count === 0) {
            $io->success('No resolutions with missing PDF text found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d resolutions with missing PDF text.', $count));

        if ($async) {
            foreach ($ids as $id) {
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $id,
                    skipAnalysis: $skipAnalysis,
                    skipVectors: $skipVectors,
                    skipPdf: false,
                ));
            }
            $io->success(sprintf('Dispatched %d resolutions to workers.', $count));
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;

        foreach ($ids as $id) {
            $resolution = $this->resolutionRepository->find($id);
            if (!$resolution || !$resolution->getSourceUrl()) {
                continue;
            }

            $ref = $resolution->getReferenceNumber();
            $io->text(sprintf('  [%d/%d] %s — %s', $processed + $errors + 1, $count, $ref, $resolution->getSourceUrl()));

            try {
                $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io);
                usleep(200_000);
            } catch (\Throwable $e) {
                $this->logger->error('PDF phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                $io->text('  <comment>PDF error: ' . $e->getMessage() . '</comment>');
                $errors++;
                continue;
            }

            try {
                if (!$skipAnalysis && !empty($resolution->getFullText()) && empty($resolution->getKeypoints())) {
                    $this->analyzeResolution($resolution, $io);
                    usleep(500_000);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Analysis phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                $io->text('  <comment>Analysis error: ' . $e->getMessage() . '</comment>');
                $errors++;
            }

            try {
                if (!$skipVectors && !empty($resolution->getFullText())) {
                    $this->vectorizeResolution($resolution, $io);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Vectorization phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                $io->text('  <comment>Vectorization error: ' . $e->getMessage() . '</comment>');
                $errors++;
            }

            try {
                $this->entityManager->flush();
                $this->entityManager->clear();
            } catch (\Exception $e) {
                $this->logger->error('Flush failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                $io->error('  Flush error: ' . $e->getMessage());
                $errors++;
                continue;
            }

            $processed++;
        }

        $io->success(sprintf('Done. %d processed, %d errors.', $processed, $errors));
        return Command::SUCCESS;
    }

    private function downloadAndProcessPdf(Resolution $resolution, string $documentUrl, SymfonyStyle $io): void
    {
        try {
            $extension = strtolower(pathinfo(parse_url($documentUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $io->text(sprintf('  Downloading %s...', $extension ?: 'document'));

            $content = $this->fetchDocumentContent($documentUrl, $io);

            if ($content === null || strlen($content) < 100) {
                $io->text('  <comment>Document too small or unavailable, skipping</comment>');
                return;
            }

            $effectiveExtension = $extension ?: 'pdf';
            if ($effectiveExtension === 'pdf' && !str_starts_with($content, '%PDF-')) {
                $io->text(sprintf('  <comment>Downloaded content is not a valid PDF (first bytes: %s), skipping storage</comment>', bin2hex(substr($content, 0, 8))));
                return;
            }

            $year = $resolution->getEntryYear() ?? date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.%s', $resolution->getSource(), $year, $safeRef, $effectiveExtension);

            $this->resolutionsStorage->write($storagePath, $content);
            $resolution->setPdfStoragePath($storagePath);
            $io->text("  Stored: $storagePath");

            $tmpFile = tempnam(sys_get_temp_dir(), 'res_doc_');
            file_put_contents($tmpFile, $content);

            $text = match ($extension) {
                'docx' => $this->extractTextFromDocx($tmpFile),
                'doc' => $this->extractTextFromDoc($tmpFile),
                default => $this->extractText($tmpFile),
            };
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                $io->text('  <comment>No extractable text</comment>');
                return;
            }

            $text = $this->cleanRawText($text);

            // Try regex-based date extraction before source-specific cleaning (which may strip signatures)
            if ($resolution->getResolutionDate() === null) {
                $dateResult = $this->dateExtractor->extractFromText($text);
                if ($dateResult['date'] !== null) {
                    $resolution->setResolutionDate($dateResult['date']);
                    $meta = $resolution->getSourceMetadata() ?? [];
                    $meta['FECHA_RESOLUCION'] = 'regex';
                    $resolution->setSourceMetadata($meta);
                    $io->text(sprintf('  Date extracted (regex): %s', $dateResult['date']->format('Y-m-d')));
                }
            }

            $text = $this->cleanTextForSource($text);
            $resolution->setFullText($this->sanitizeUtf8($text));
            $io->text(sprintf('  Extracted %d chars of text', mb_strlen($text)));
        } catch (\Exception $e) {
            $this->logger->warning('Document download/processing failed', [
                'reference' => $resolution->getReferenceNumber(),
                'url' => $documentUrl,
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>Error: ' . $e->getMessage() . '</comment>');
        }
    }

    private function analyzeResolution(Resolution $resolution, SymfonyStyle $io): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        $io->text('  Analyzing with AI...');
        $cleanedText = $this->analyzer->cleanText($fullText);

        try {
            $result = $this->analyzer->analyze($cleanedText);

            $resolution->setFullText($result['formatted_text']);
            $this->analyzer->applyAnalysisResult($resolution, $result);

            $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 100) . '...'));
            $io->text(sprintf('  Keypoints: %d | Dates: res=%s claim=%s info=%s',
                count($result['keypoints']),
                $result['resolution_date'] ?? 'n/a',
                $result['claim_date'] ?? 'n/a',
                $result['info_request_date'] ?? 'n/a',
            ));
        } catch (\Exception $e) {
            $this->logger->error('AI analysis failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>Analysis error: ' . $e->getMessage() . '</comment>');
        }
    }

    private function vectorizeResolution(Resolution $resolution, SymfonyStyle $io): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        try {
            $baseMeta = array_filter([
                Metadata::KEY_SOURCE => $resolution->getReferenceNumber(),
                'reference' => $resolution->getReferenceNumber(),
                'outcome' => $resolution->getOutcome(),
                'source' => $resolution->getSource(),
                'scope' => $resolution->getScope(),
                'subject' => $this->sanitizeUtf8($resolution->getSubject()),
                'publicBody' => $this->sanitizeUtf8($resolution->getPublicBodyName()),
                'entityType' => $resolution->getEntityType(),
            ], fn ($v) => $v !== null);

            if ($resolution->getAutonomousCommunity()) {
                $baseMeta['autonomousCommunity'] = $resolution->getAutonomousCommunity()->getName();
            }

            $chunks = $this->chunkText($fullText);
            $documents = [];

            foreach ($chunks as $index => $chunkText) {
                try {
                    $embedding = $this->embeddingGenerator->generate($chunkText);
                } catch (\Exception $e) {
                    $this->logger->error('Embedding error', [
                        'reference' => $resolution->getReferenceNumber(),
                        'chunk' => $index,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata(array_merge($baseMeta, [
                        Metadata::KEY_TEXT => $chunkText,
                        'chunkIndex' => $index,
                        'type' => 'fulltext',
                    ])),
                );

                usleep(100_000);
            }

            $keypoints = $resolution->getKeypoints();
            if (!empty($keypoints)) {
                $keypointsText = implode("\n\n", $keypoints);
                try {
                    $embedding = $this->embeddingGenerator->generate($keypointsText);
                    $documents[] = new VectorDocument(
                        id: Uuid::v7(),
                        vector: new Vector($embedding),
                        metadata: new Metadata(array_merge($baseMeta, [
                            Metadata::KEY_TEXT => $keypointsText,
                            'chunkIndex' => -1,
                            'type' => 'keypoints',
                        ])),
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Keypoints embedding error', [
                        'reference' => $resolution->getReferenceNumber(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!empty($documents)) {
                $this->vectorStore->add($documents);
                $io->text(sprintf('  Vectorized: %d chunks + %s keypoints',
                    count($chunks),
                    !empty($keypoints) ? '1' : '0',
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('Vectorization failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>Vectorization error: ' . $e->getMessage() . '</comment>');
        }
    }

    private function extractText(string $filePath): string
    {
        // Delegates to the shared extractor, which transcribes via vision-LLM any
        // pages that lack a text layer (image-only scans) and merges them back in.
        return $this->pdfOcrTranscriber->extractTextWithOcr($filePath);
    }

    private function extractTextFromDoc(string $filePath): string
    {
        $process = new Process(['antiword', $filePath]);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        return '';
    }

    private function extractTextFromDocx(string $filePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $t = $element->getText();
                    if (is_string($t)) {
                        $text .= $t . "\n";
                    } elseif (is_object($t) && method_exists($t, 'getText')) {
                        $text .= $t->getText() . "\n";
                    }
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText();
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return $text;
    }

    private function cleanRawText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x{FFFD}]/u', '', $text);
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text);
        $text = preg_replace('/^\s*\d{1,3}\s*$/m', '', $text);

        return trim($text);
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return preg_replace('/[\x{FFFD}]/u', '', $value);
    }

    /**
     * Source-specific text cleaning hook. Override in commands for custom cleaning.
     */
    protected function cleanTextForSource(string $text): string
    {
        return $text;
    }

    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['path'])) {
            return $url;
        }

        $segments = explode('/', $parts['path']);
        $encoded = array_map(fn (string $s) => rawurlencode(rawurldecode($s)), $segments);
        $parts['path'] = implode('/', $encoded);

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        $result .= $parts['path'];
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        return $result;
    }

    private function fetchDocumentContent(string $url, SymfonyStyle $io): ?string
    {
        $url = $this->encodeUrlPath($url);
        $timeout = str_contains($url, 'gobiernoabierto.navarra.es') ? 2 : 60;
        $options = ['timeout' => $timeout];

        // comunidad.madrid blocks requests without a browser-like User-Agent
        if (str_contains($url, 'comunidad.madrid')) {
            $options['headers'] = [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ];
        }

        // ctpdandalucia.es has an untrusted SSL certificate
        if (str_contains($url, 'ctpdandalucia.es')) {
            $options['verify_peer'] = false;
        }

        try {
            $response = $this->httpClient->request('GET', $url, $options);
            return $response->getContent();
        } catch (\Exception $e) {
            $io->text(sprintf('  <comment>Direct download failed: %s</comment>', $e->getMessage()));
        }

        // Fallback: Wayback Machine
        $waybackUrl = 'https://web.archive.org/web/' . $url;
        $io->text('  Retrying via Wayback Machine...');
        try {
            $response = $this->httpClient->request('GET', $waybackUrl, ['timeout' => 60]);
            $content = $response->getContent();
            $io->text('  <info>Fetched from Wayback Machine</info>');
            return $content;
        } catch (\Exception $e) {
            $this->logger->warning('Wayback Machine fallback also failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $io->text(sprintf('  <comment>Wayback fallback failed: %s</comment>', $e->getMessage()));
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        if (strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > self::MAX_CHUNK_CHARS) {
                $chunks[] = trim($current);
                $current = $paragraph;
            } else {
                $current .= ($current !== '' ? "\n\n" : '') . $paragraph;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
