<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

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

    private function downloadAndProcessPdf(Resolution $resolution, string $pdfUrl, SymfonyStyle $io): void
    {
        try {
            $io->text('  Downloading PDF...');
            $response = $this->httpClient->request('GET', $pdfUrl, [
                'timeout' => 60,
            ]);
            $pdfContent = $response->getContent();

            if (strlen($pdfContent) < 100) {
                $io->text('  <comment>PDF too small, skipping</comment>');
                return;
            }

            $year = $resolution->getEntryYear() ?? date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.pdf', $resolution->getSource(), $year, $safeRef);

            $this->resolutionsStorage->write($storagePath, $pdfContent);
            $resolution->setPdfStoragePath($storagePath);
            $io->text("  Stored PDF: $storagePath");

            $tmpFile = tempnam(sys_get_temp_dir(), 'res_pdf_');
            file_put_contents($tmpFile, $pdfContent);

            $text = $this->extractText($tmpFile);
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                $io->text('  <comment>No extractable text (scanned PDF)</comment>');
                return;
            }

            $text = $this->cleanRawText($text);
            $resolution->setFullText($this->sanitizeUtf8($text));
            $io->text(sprintf('  Extracted %d chars of text', mb_strlen($text)));

            // Try regex-based date extraction from raw text
            $dateResult = $this->dateExtractor->extractFromText($text);
            if ($dateResult['date'] !== null) {
                $resolution->setResolutionDate($dateResult['date']);
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'regex';
                $resolution->setSourceMetadata($meta);
                $io->text(sprintf('  Date extracted (regex): %s', $dateResult['date']->format('Y-m-d')));
            }
        } catch (\Exception $e) {
            $this->logger->warning('PDF download/processing failed', [
                'reference' => $resolution->getReferenceNumber(),
                'url' => $pdfUrl,
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>PDF error: ' . $e->getMessage() . '</comment>');
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
            $resolution->setSummary($result['summary']);
            $resolution->setKeypoints($result['keypoints']);

            if (!empty($result['subject'])) {
                $resolution->setSubject(mb_substr($result['subject'], 0, 500));
            }

            $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
            if ($result['resolution_date'] && $existingDateSource !== 'regex') {
                try {
                    $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                    $meta = $resolution->getSourceMetadata() ?? [];
                    $meta['FECHA_RESOLUCION'] = 'LLM';
                    $resolution->setSourceMetadata($meta);
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

            $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 100) . '...'));
            $io->text(sprintf('  Keypoints: %d | Dates: res=%s claim=%s',
                count($result['keypoints']),
                $result['resolution_date'] ?? 'n/a',
                $result['claim_date'] ?? 'n/a',
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
