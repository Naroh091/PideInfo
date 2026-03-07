<?php

namespace App\Command;

use App\Service\AI\EmbeddingGenerator;
use App\Service\Document\PdfTextExtractor;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:ctbg:load-criteria',
    description: 'Load CTBG interpretive criteria PDFs into PostgreSQL vector store',
)]
class LoadCGBGCriteriaCommand extends Command
{
    private const DATA_PATH = '/home/app/var/storage/data';

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_criteria')]
        private readonly StoreInterface $ctbgCriteriaStore,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EmbeddingGenerator $embeddingGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('CTBG Criteria Loader');

        if (!$dryRun && $this->ctbgCriteriaStore instanceof ManagedStoreInterface) {
            $io->section('Setting up PostgreSQL vector store...');
            $this->ctbgCriteriaStore->setup();
            $io->success('Collection created/verified.');
        } elseif (!$dryRun) {
            $io->warning('Store does not implement ManagedStoreInterface, cannot auto-setup collection.');
        }

        $finder = new Finder();
        $finder->files()->in(self::DATA_PATH)->name('*.pdf')->sortByName();

        if (!$finder->hasResults()) {
            $io->warning('No PDF files found in ' . self::DATA_PATH);
            return Command::SUCCESS;
        }

        $io->section('Processing PDF files...');
        $totalChunks = 0;
        $processedFiles = 0;

        foreach ($finder as $file) {
            $filename = $file->getFilename();
            $io->text("Processing: $filename");

            $metadata = $this->parseFilename($filename);
            if ($metadata === null) {
                $io->warning("  Skipping - filename doesn't match expected pattern C[X]_YYYY_*.pdf");
                continue;
            }

            try {
                $chunks = $this->pdfTextExtractor->extractChunks($file->getRealPath());
            } catch (\Exception $e) {
                $io->error("  Error extracting text: " . $e->getMessage());
                continue;
            }

            $io->text("  Found " . count($chunks) . " chunks");

            $documents = [];
            foreach ($chunks as $index => $chunk) {
                if ($dryRun) {
                    $io->text("  [DRY-RUN] Chunk $index: " . strlen($chunk['text']) . " chars, pages {$chunk['pageStart']}-{$chunk['pageEnd']}");
                    $totalChunks++;
                    continue;
                }

                try {
                    $embedding = $this->embeddingGenerator->generate($chunk['text']);
                } catch (\Exception $e) {
                    $io->error("  Error generating embedding for chunk $index: " . $e->getMessage());
                    continue;
                }

                $docMetadata = new Metadata([
                    Metadata::KEY_TEXT => $chunk['text'],
                    Metadata::KEY_SOURCE => $filename,
                    'criterion' => $metadata['criterion'],
                    'year' => $metadata['year'],
                    'topic' => $metadata['topic'],
                    'pageStart' => $chunk['pageStart'],
                    'pageEnd' => $chunk['pageEnd'],
                    'chunkIndex' => $index,
                ]);

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: $docMetadata,
                );

                $totalChunks++;
            }

            if (!$dryRun && !empty($documents)) {
                $this->ctbgCriteriaStore->add($documents);
                $io->text("  Stored " . count($documents) . " chunks in PostgreSQL");
            }

            $processedFiles++;
        }

        $io->newLine();

        if ($dryRun) {
            $io->success("Dry run complete. Would have stored $totalChunks chunks from $processedFiles files.");
        } else {
            $io->success("Successfully stored $totalChunks chunks from $processedFiles files into PostgreSQL.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse the filename to extract metadata
     * Expected pattern: C[X]_YYYY_[description].pdf
     * Examples:
     *   C1_2015_AccesoRPT_retribuciones_Censurado.pdf
     *   C2_2016_sobre_agendas_conjuntoAEPD_Censurado.pdf
     *
     * @return array{criterion: string, year: int, topic: string}|null
     */
    private function parseFilename(string $filename): ?array
    {
        if (!preg_match('/^C(\d+)_(\d{4})[-_](.+)\.pdf$/i', $filename, $matches)) {
            return null;
        }

        $topic = str_replace('_', ' ', $matches[3]);
        $topic = preg_replace('/\s*(Censurado|censurado)\s*$/', '', $topic);
        $topic = trim($topic);

        return [
            'criterion' => 'C' . $matches[1],
            'year' => (int) $matches[2],
            'topic' => $topic,
        ];
    }
}
