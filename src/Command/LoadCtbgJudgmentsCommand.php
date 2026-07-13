<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Judgment;
use App\Message\ProcessJudgmentMessage;
use App\Repository\JudgmentRepository;
use App\Service\Judgment\CtbgRecursosXlsxReader;
use App\Service\Judgment\JudgmentImporter;
use App\Service\Judgment\JudgmentProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Imports the CTBG recursos listing: every judicial ruling on a recurso against a CTBG
 * resolution (Juzgados Centrales, Audiencia Nacional, Tribunal Supremo).
 *
 * PDF download, AI analysis and vectorization are later phases of the pipeline; this command
 * establishes the case skeleton — who sued whom over which resolution, how each instance
 * ruled, and whether the outcome is firm.
 */
#[AsCommand(
    name: 'app:judgments:load-ctbg',
    description: 'Importa los recursos judiciales contra resoluciones del CTBG (XLSX oficial): sentencias de instancia, apelación y casación, enlazadas a sus resoluciones.',
)]
final class LoadCtbgJudgmentsCommand extends Command
{
    public function __construct(
        private readonly CtbgRecursosXlsxReader $reader,
        private readonly JudgmentImporter $importer,
        private readonly JudgmentProcessor $processor,
        private readonly JudgmentRepository $judgments,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de filas (recursos) a procesar.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Leer un XLSX local en vez de descargarlo.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No persiste nada; solo informa.')
            ->addOption('relink', null, InputOption::VALUE_NONE, 'Solo re-ejecuta el casado de refs pendientes contra las resoluciones (tras importar años antiguos del CTBG).')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Despacha el procesado (PDF + análisis) a los workers en vez de hacerlo inline.')
            ->addOption('skip-pdf', null, InputOption::VALUE_NONE, 'No descarga PDFs.')
            ->addOption('skip-analysis', null, InputOption::VALUE_NONE, 'No ejecuta el análisis con IA.')
            ->addOption('skip-vectors', null, InputOption::VALUE_NONE, 'No genera embeddings.')
            ->addOption('vision', null, InputOption::VALUE_NONE, 'Fuerza la transcripción por visión de todas las páginas del PDF.')
            ->addOption('reanalyze', null, InputOption::VALUE_NONE, 'Reanaliza también las sentencias YA analizadas que no tienen effective_outcome (se analizaron antes del arreglo del recorte: su fallo pudo no llegar al modelo, y una sentencia analizada sin su fallo queda INVERTIDA, no incompleta).')
            ->addOption('process-limit', null, InputOption::VALUE_REQUIRED, 'Máximo de sentencias a procesar (PDF + análisis). Para muestrear calidad antes de procesar las ~370.')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Procesa SOLO esta sentencia (por referenceNumber, ej. "AN/51/2022"), forzando re-análisis. Salta el import.')
            ->addOption('pdf', null, InputOption::VALUE_REQUIRED, 'Con --reference: usa este PDF local en vez de descargarlo. La vía para las sentencias de CENDOJ conseguidas a mano.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reference') !== null) {
            return $this->processOne((string) $input->getOption('reference'), $input, $io);
        }

        if ($input->getOption('relink')) {
            $result = $this->importer->relink();
            $io->success(sprintf(
                '%d sentencias tenían refs sin casar · %d han casado ahora con alguna resolución.',
                $result['judgments'],
                $result['nowLinked'],
            ));

            return Command::SUCCESS;
        }

        $io->section('Leyendo el listado de recursos del CTBG');

        $reader = $input->getOption('file') !== null
            ? $this->reader->withLocalFile((string) $input->getOption('file'))
            : $this->reader;

        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $judgments = $reader->fetchAll($limit);

        $io->writeln(sprintf('  %d sentencias leídas del listado.', count($judgments)));

        $stats = $this->importer->import($judgments, dryRun: (bool) $input->getOption('dry-run'));

        // The match rate is the health metric of this import: refs that do not match are
        // stored on the judgment and re-linked later with --relink.
        $matchRate = $stats['refsTotal'] > 0
            ? round(100 * $stats['refsMatched'] / $stats['refsTotal'], 1)
            : 0.0;

        $io->definitionList(
            ['Sentencias creadas' => $stats['created']],
            ['Sentencias actualizadas' => $stats['updated']],
            ['Refs a resoluciones' => $stats['refsTotal']],
            ['Refs casadas' => sprintf('%d (%.1f%%)', $stats['refsMatched'], $matchRate)],
            ['Refs ilegibles' => $stats['unparsed']],
        );

        if ($matchRate < 50.0 && $stats['refsTotal'] > 0) {
            $io->warning(
                'Menos de la mitad de las refs casan con resoluciones en BD. Esperable mientras falten los años '
                . '2015-2017 del CTBG: las refs quedan guardadas en cada sentencia y `--relink` las casará cuando se importen.',
            );
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry-run: no se ha persistido nada ni se procesan PDFs.');

            return Command::SUCCESS;
        }

        $io->success('Importación completada.');

        if (!$input->getOption('skip-pdf') || !$input->getOption('skip-analysis')) {
            $this->processPending($input, $io);
        }

        return Command::SUCCESS;
    }

    private function processOne(string $reference, InputInterface $input, SymfonyStyle $io): int
    {
        $judgment = $this->judgments->findByReference($reference, Judgment::SOURCE_CTBG_RECURSOS);

        if ($judgment === null) {
            $io->error(sprintf('No existe la sentencia «%s» (fuente ctbg_recursos).', $reference));

            return Command::FAILURE;
        }

        $io->section('Procesando ' . $judgment->getReferenceNumber());

        $localPdf = $input->getOption('pdf') !== null ? (string) $input->getOption('pdf') : null;

        if ($localPdf !== null && !is_file($localPdf)) {
            $io->error(sprintf('El PDF «%s» no existe.', $localPdf));

            return Command::FAILURE;
        }

        // Force a re-analysis: clear the analyzer-owned fields so the processor treats it as
        // pending. The listing-owned fields (number, firmeza) are untouched. With a local PDF,
        // the text is re-extracted from the file handed to us.
        $judgment->setTransparencyStance(null);
        if ($localPdf !== null) {
            $judgment->setFullText(null);
        }

        $outcome = $this->processor->process(
            $judgment,
            skipPdf: (bool) $input->getOption('skip-pdf'),
            skipAnalysis: (bool) $input->getOption('skip-analysis'),
            skipVectors: (bool) $input->getOption('skip-vectors'),
            forceVision: (bool) $input->getOption('vision'),
            onProgress: static fn (string $line) => $io->text('  ' . $line),
            localPdfPath: $localPdf,
        );

        $this->entityManager->flush();
        $io->success(sprintf('%s → %s', $judgment->getReferenceNumber(), $outcome));

        return Command::SUCCESS;
    }

    /**
     * PDF + analysis for every judgment still missing them. Inline and async both go through
     * JudgmentProcessor — parity is structural.
     */
    private function processPending(InputInterface $input, SymfonyStyle $io): void
    {
        $limit = $input->getOption('process-limit') !== null ? (int) $input->getOption('process-limit') : null;
        $reanalyze = (bool) $input->getOption('reanalyze');
        $ids = $this->judgments->findIdsPendingProcessing(Judgment::SOURCE_CTBG_RECURSOS, $limit, $reanalyze);

        // Already-analysed judgments carry a stance, and the processor skips anything that has one.
        // Clearing it is what makes them pending again — and it must be FLUSHED before the async
        // dispatch, or the worker would load the entity still holding its old stance and skip it.
        //
        // NOT when analysis is being skipped: that would strip the stance and never put one back,
        // leaving the judgment unusable (JudgmentRetriever refuses to serve a judgment without it).
        if ($reanalyze && !$input->getOption('skip-analysis') && $ids !== []) {
            foreach ($ids as $id) {
                $judgment = $this->judgments->find($id);
                $judgment?->setTransparencyStance(null);
            }
            $this->entityManager->flush();
        }

        if ($ids === []) {
            $io->writeln('No hay sentencias pendientes de procesar.');

            return;
        }

        $io->section(sprintf('Procesando %d sentencias (PDF + análisis + vectores)', count($ids)));

        if ($input->getOption('async')) {
            foreach ($ids as $id) {
                $this->messageBus->dispatch(new ProcessJudgmentMessage(
                    judgmentId: $id,
                    skipPdf: (bool) $input->getOption('skip-pdf'),
                    skipAnalysis: (bool) $input->getOption('skip-analysis'),
                    skipVectors: (bool) $input->getOption('skip-vectors'),
                    forceVision: (bool) $input->getOption('vision'),
                ));
            }

            $io->success(sprintf('%d sentencias despachadas a los workers (transporte analysis).', count($ids)));

            return;
        }

        $done = 0;
        foreach ($ids as $id) {
            $judgment = $this->judgments->find($id);
            if ($judgment === null) {
                continue;
            }

            $io->text(sprintf('  [%d/%d] %s', ++$done, count($ids), $judgment->getReferenceNumber()));

            $outcome = $this->processor->process(
                $judgment,
                skipPdf: (bool) $input->getOption('skip-pdf'),
                skipAnalysis: (bool) $input->getOption('skip-analysis'),
                skipVectors: (bool) $input->getOption('skip-vectors'),
                forceVision: (bool) $input->getOption('vision'),
                onProgress: static fn (string $line) => $io->text('      ' . $line),
            );

            if (in_array($outcome, ['needs_browser', 'no_url', 'no_pdf', 'no_text'], true)) {
                $io->text('      <comment>' . $outcome . '</comment>');
            }

            // Flush + clear per judgment: some sentencias are 60+ pages of text.
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        $io->success(sprintf('%d sentencias procesadas.', $done));
    }
}
