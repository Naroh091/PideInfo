<?php

namespace App\MessageHandler;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class ProcessResolutionHandler
{
    private const MAX_CHUNK_CHARS = 4000;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessResolutionMessage $message): void
    {
        $resolution = $this->resolutionRepository->find($message->resolutionId);
        if (!$resolution) {
            $this->logger->warning('Resolution not found for processing', ['id' => $message->resolutionId]);
            return;
        }

        $ref = $resolution->getReferenceNumber();
        $this->logger->info("Processing resolution $ref");

        // Step 1: Download PDF if needed
        if (!$message->skipPdf && $resolution->getSourceUrl() && empty(trim($resolution->getFullText()))) {
            $this->downloadAndProcessPdf($resolution);
        }

        // Step 2: AI Analysis if needed (use keypoints as indicator — summary may be pre-filled from API)
        if (!$message->skipAnalysis && !empty(trim($resolution->getFullText())) && empty($resolution->getKeypoints())) {
            $this->analyzeResolution($resolution);
        }

        // Step 3: Vectorize if needed
        if (!$message->skipVectors && !empty(trim($resolution->getFullText()))) {
            $this->vectorizeResolution($resolution);
        }

        $this->entityManager->flush();
        $this->logger->info("Finished processing resolution $ref");
    }

    private function downloadAndProcessPdf(Resolution $resolution): void
    {
        try {
            $response = $this->httpClient->request('GET', $resolution->getSourceUrl(), [
                'timeout' => 60,
            ]);
            $pdfContent = $response->getContent();

            if (strlen($pdfContent) < 100) {
                return;
            }

            // Store in Flysystem
            $year = $resolution->getEntryYear() ?? date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.pdf', $resolution->getSource(), $year, $safeRef);

            $this->resolutionsStorage->write($storagePath, $pdfContent);
            $resolution->setPdfStoragePath($storagePath);

            // Extract text
            $tmpFile = tempnam(sys_get_temp_dir(), 'res_pdf_');
            file_put_contents($tmpFile, $pdfContent);
            $text = $this->extractText($tmpFile);
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                return;
            }

            $text = $this->cleanRawText($text);
            $resolution->setFullText($this->sanitizeUtf8($text));

            $this->logger->info('PDF processed', [
                'reference' => $resolution->getReferenceNumber(),
                'chars' => mb_strlen($text),
                'storage' => $storagePath,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('PDF download/processing failed', [
                'reference' => $resolution->getReferenceNumber(),
                'url' => $resolution->getSourceUrl(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function analyzeResolution(Resolution $resolution): void
    {
        $cleanedText = $this->analyzer->cleanText($resolution->getFullText());

        try {
            $result = $this->analyzer->analyze($cleanedText);

            $resolution->setFullText($result['formatted_text']);
            $resolution->setSummary($result['summary']);
            $resolution->setKeypoints($result['keypoints']);

            if (!empty($result['subject'])) {
                $resolution->setSubject(mb_substr($result['subject'], 0, 500));
            }

            if ($result['resolution_date']) {
                try {
                    $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                } catch (\Exception) {
                }
            }

            if ($result['claim_date']) {
                try {
                    $resolution->setClaimDate(new \DateTimeImmutable($result['claim_date']));
                } catch (\Exception) {
                }
            }

            if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
                $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                $resolution->setDaysToResolve($days);
            }

            $this->logger->info('AI analysis complete', [
                'reference' => $resolution->getReferenceNumber(),
                'keypoints' => count($result['keypoints']),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AI analysis failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // Let Messenger retry
        }
    }

    private function vectorizeResolution(Resolution $resolution): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

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

        $documents = [];

        // Full text chunks
        $chunks = $this->chunkText($fullText);
        foreach ($chunks as $index => $chunkText) {
            try {
                $embedding = $this->embeddingGenerator->generate($chunkText);
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
            } catch (\Exception $e) {
                $this->logger->error('Embedding error', [
                    'reference' => $resolution->getReferenceNumber(),
                    'chunk' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Keypoints as single document
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
        }
    }

    // --- Utilities ---

    private function extractText(string $filePath): string
    {
        $process = new Process(['pdftotext', '-layout', $filePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful() && strlen(trim($process->getOutput())) > 100) {
            return $process->getOutput();
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception) {
            return '';
        }
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
        $text = preg_replace('/^\d+\s*$/m', '', $text);

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
