<?php

namespace App\Command;

use App\Service\AI\EmbeddingGenerator;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:ctbg:load-resolutions',
    description: 'Load CTBG resolution PDFs into PostgreSQL vector store',
)]
class LoadCTBGResolutionsCommand extends Command
{
    private const MAX_CHUNK_CHARS = 4000;
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $geminiModel,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to directory containing resolution PDFs')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of files to process')
            ->addOption('skip-empty', null, InputOption::VALUE_NONE, 'Skip PDFs with no extractable text (scanned)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $skipEmpty = $input->getOption('skip-empty');
        $path = $input->getArgument('path');

        $io->title('CTBG Resolutions Loader');

        if (!is_dir($path)) {
            $io->error("Directory not found: $path");
            return Command::FAILURE;
        }

        if (!$dryRun && $this->ctbgResolutionsStore instanceof ManagedStoreInterface) {
            $io->section('Setting up PostgreSQL vector store...');
            $this->ctbgResolutionsStore->setup([
                'vector_type' => 'halfvec',
                'vector_size' => 3072,
                'index_method' => 'hnsw',
                'index_opclass' => 'halfvec_cosine_ops',
            ]);
            $io->success('Collection created/verified.');
        }

        $finder = new Finder();
        $finder->files()->in($path)->name('*.pdf')->sortByName();

        if (!$finder->hasResults()) {
            $io->warning('No PDF files found in ' . $path);
            return Command::SUCCESS;
        }

        $io->section('Processing resolution PDFs...');
        $totalChunks = 0;
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($finder as $file) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $filename = $file->getFilename();
            $io->text("Processing: $filename");

            // Extract text with pdftotext
            $text = $this->extractText($file->getRealPath());

            if (strlen(trim($text)) < 100) {
                $skipped++;
                if ($skipEmpty) {
                    $io->text('  <comment>Skipped — no extractable text (scanned PDF)</comment>');
                    continue;
                }
                $io->warning("  No extractable text (scanned PDF) — skipping");
                continue;
            }

            // Clean text
            $text = $this->cleanText($text);

            // Extract metadata with Gemini
            try {
                $metadata = $this->extractMetadata($text, $filename);
            } catch (\Exception $e) {
                $io->error("  Error extracting metadata: " . $e->getMessage());
                $errors++;
                continue;
            }

            $io->text(sprintf(
                '  Ref: %s | Date: %s | Outcome: %s | Body: %s',
                $metadata['reference'],
                $metadata['date'] ?? '?',
                $metadata['outcome'],
                $metadata['publicBody'] ?? '?'
            ));

            // Chunk the text
            $chunks = $this->chunkText($text);
            $io->text("  Chunks: " . count($chunks));

            $documents = [];
            foreach ($chunks as $index => $chunkText) {
                if ($dryRun) {
                    $totalChunks++;
                    continue;
                }

                try {
                    $embedding = $this->embeddingGenerator->generate($chunkText);
                } catch (\Exception $e) {
                    $io->error("  Error generating embedding for chunk $index: " . $e->getMessage());
                    continue;
                }

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata([
                        Metadata::KEY_TEXT => $chunkText,
                        Metadata::KEY_SOURCE => $filename,
                        'reference' => $metadata['reference'],
                        'date' => $metadata['date'],
                        'outcome' => $metadata['outcome'],
                        'publicBody' => $metadata['publicBody'],
                        'topic' => $metadata['topic'],
                        'chunkIndex' => $index,
                    ]),
                );

                $totalChunks++;
            }

            if (!$dryRun && !empty($documents)) {
                $this->ctbgResolutionsStore->add($documents);
                $io->text("  Stored " . count($documents) . " chunks");
            }

            $processed++;

            // Rate limit for Gemini API
            usleep(500000);
        }

        $io->newLine();
        $io->success(sprintf(
            '%s complete. %d resolutions processed, %d chunks %s, %d skipped, %d errors.',
            $dryRun ? 'Dry run' : 'Import',
            $processed,
            $totalChunks,
            $dryRun ? 'found' : 'stored',
            $skipped,
            $errors
        ));

        return Command::SUCCESS;
    }

    private function extractText(string $filePath): string
    {
        // Try pdftotext first (better quality)
        $process = new Process(['pdftotext', '-layout', $filePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful() && strlen(trim($process->getOutput())) > 100) {
            return $process->getOutput();
        }

        // Fallback to PHP PDF parser
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception) {
            return '';
        }
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        // Remove repeated footer/header lines (FIRMANTE, page numbers)
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text);
        $text = preg_replace('/^\d+\s*$/m', '', $text);

        return trim($text);
    }

    /**
     * @return array{reference: string, date: string|null, outcome: string, publicBody: string|null, topic: string|null}
     */
    private function extractMetadata(string $text, string $filename): array
    {
        // Use first ~2000 chars + last ~1000 chars for metadata extraction (cheaper)
        $excerpt = mb_substr($text, 0, 2000);
        if (strlen($text) > 3000) {
            $excerpt .= "\n\n[...]\n\n" . mb_substr($text, -1000);
        }

        $prompt = <<<PROMPT
Extracto de una resolución del CTBG de España. Extrae los metadatos.

$excerpt
PROMPT;

        $url = sprintf(self::GEMINI_ENDPOINT, $this->geminiModel) . '?key=' . $this->geminiApiKey;

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 512,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => [
                            'type' => 'string',
                            'description' => 'Número de referencia (N/REF, formato R/XXXX/YYYY)',
                        ],
                        'date' => [
                            'type' => 'string',
                            'description' => 'Fecha de la resolución en formato YYYY-MM-DD',
                        ],
                        'outcome' => [
                            'type' => 'string',
                            'enum' => ['estimada', 'desestimada', 'parcial', 'inadmitida'],
                            'description' => 'Resultado de la reclamación',
                        ],
                        'publicBody' => [
                            'type' => 'string',
                            'description' => 'Organismo público reclamado',
                        ],
                        'topic' => [
                            'type' => 'string',
                            'description' => 'Tema principal de la solicitud de información (breve)',
                        ],
                    ],
                    'required' => ['reference', 'outcome'],
                ],
            ],
        ];

        $jsonPayload = json_encode($payload);
        $metadata = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 429 || $httpCode === 503) {
                $this->logger->warning('Gemini rate limited, retrying', [
                    'filename' => $filename,
                    'httpCode' => $httpCode,
                    'attempt' => $attempt,
                ]);
                sleep($attempt * 2);
                continue;
            }

            if ($httpCode !== 200) {
                $this->logger->error('Gemini API error', [
                    'filename' => $filename,
                    'httpCode' => $httpCode,
                    'response' => mb_substr($response, 0, 1000),
                ]);
                throw new \RuntimeException('Gemini API error (HTTP ' . $httpCode . ')');
            }

            $data = json_decode($response, true);
            if (!$data) {
                $this->logger->error('Failed to decode Gemini response', [
                    'filename' => $filename,
                    'response' => mb_substr($response, 0, 1000),
                ]);
                throw new \RuntimeException('Failed to decode Gemini response');
            }

            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $metadata = json_decode($jsonText, true);

            if ($metadata) {
                break;
            }

            $this->logger->warning('Gemini returned truncated JSON, retrying', [
                'filename' => $filename,
                'attempt' => $attempt,
                'jsonText' => $jsonText,
                'finishReason' => $data['candidates'][0]['finishReason'] ?? 'unknown',
            ]);
            sleep(1);
        }

        if (!$metadata) {
            $this->logger->error('Failed to extract metadata after 3 attempts', [
                'filename' => $filename,
                'lastJsonText' => $jsonText ?? 'empty',
                'lastResponse' => mb_substr($response ?? '', 0, 1000),
            ]);
            throw new \RuntimeException('Failed to parse metadata JSON after 3 attempts');
        }

        return [
            'reference' => $metadata['reference'] ?? $this->referenceFromFilename($filename),
            'date' => $metadata['date'] ?? null,
            'outcome' => $metadata['outcome'] ?? 'unknown',
            'publicBody' => $metadata['publicBody'] ?? null,
            'topic' => $metadata['topic'] ?? null,
        ];
    }

    private function referenceFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // R-0532-2016 → R/0532/2016 or 0282-2015 → 0282/2015
        $parts = explode('-', $name);
        return implode('/', $parts);
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
