<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Resolution;
use App\Message\ReconcileOutcomeMessage;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\OutcomeReconciler;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Repairs resolutions whose stored outcome contradicts their own stored summary — the corpus
 * analysed before the ingestion gate existed.
 *
 * Found in R/0701/2018 (BOSCO): outcome `favorable`, summary «el Consejo estima parcialmente la
 * reclamación, denegando solo el código fuente». The label came from the fallo's opening line and
 * missed the carve-out — and it then overwrote the `partial` the CTBG listing had got right. An
 * estimación parcial sold as favorable overstates the precedent the agent cites: it hides that the
 * Council itself refused part of what was asked.
 *
 * Detecting the contradiction is free (both fields are already stored). RESOLVING it is not ours to
 * do: the same second turn the ingestion now runs — {@see OutcomeReconciler} — hands the model its
 * own answer and the literal fallo, and IT decides. A regex would be overruling a reader it cannot
 * replace.
 *
 * Dry-run by default: lists the contradictions without calling the model. `--apply` re-asks and
 * writes, through the ORM so ResolutionIndexListener reindexes each corrected row.
 */
#[AsCommand(
    name: 'app:resolutions:fix-contradicted-outcomes',
    description: 'Corrige resoluciones cuyo outcome contradice su propio resumen (estimación parcial vendida como total)',
)]
final class FixContradictedOutcomesCommand extends Command
{
    private const REASONS = [
        'self' => 'su propio resumen',
        'source' => 'el listado del consejo',
    ];

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Escribe los cambios (por defecto: dry-run)');
        $this->addOption('async', null, InputOption::VALUE_NONE, 'Despacha el desempate a los workers (transporte analysis) en vez de hacerlo inline');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de resoluciones a tocar');
    }

    public function __construct(
        private readonly ResolutionRepository $resolutions,
        private readonly OutcomeReconciler $reconciler,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $async = (bool) $input->getOption('async');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        $qb = $this->resolutions->createQueryBuilder('r')
            ->where('r.outcome IN (:overstated)')
            ->setParameter('overstated', [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_UNFAVORABLE])
            ->orderBy('r.referenceNumber', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $candidates = $qb->getQuery()->toIterable();

        $rows = [];
        $touched = 0;

        foreach ($candidates as $resolution) {
            /** @var Resolution $resolution */
            $label = $resolution->getOutcome();
            $reason = ResolutionAnalyzer::contradiction($resolution, $label);

            if ($reason === null) {
                continue;
            }

            if (!$apply) {
                // Dry-run costs nothing: it lists what WOULD be re-asked, without calling the model.
                $rows[] = [
                    $resolution->getReferenceNumber(),
                    $resolution->getSource(),
                    self::REASONS[$reason],
                    $label . ' → ?',
                ];
                ++$touched;
                continue;
            }

            if ($async) {
                $this->messageBus->dispatch(new ReconcileOutcomeMessage($resolution->getId()));
                $rows[] = [$resolution->getReferenceNumber(), $resolution->getSource(), self::REASONS[$reason], 'despachada'];
                ++$touched;
                continue;
            }

            // The model decides, not us: same second turn the ingestion now does.
            $verdict = $this->reconciler->reconcile($resolution, $label, $reason);

            if ($verdict === null) {
                $rows[] = [$resolution->getReferenceNumber(), $resolution->getSource(), self::REASONS[$reason], $label . ' → (sin respuesta)'];
                continue;
            }

            $meta = $resolution->getSourceMetadata() ?? [];
            $meta[Resolution::META_OUTCOME_SELF_CONTRADICTED] = [
                'label' => $label,
                'reason' => $reason,
                'resolved' => true,
                'outcome' => $verdict['outcome'],
                'reasoning' => $verdict['reasoning'],
            ];
            $resolution->setSourceMetadata($meta);
            $resolution->setOutcome($verdict['outcome']);

            $rows[] = [
                $resolution->getReferenceNumber(),
                $resolution->getSource(),
                self::REASONS[$reason],
                $label === $verdict['outcome'] ? $label . ' (confirmado)' : $label . ' → ' . $verdict['outcome'],
                mb_strimwidth($verdict['reasoning'], 0, 70, '…'),
            ];

            if ($label !== $verdict['outcome']) {
                ++$touched;
            }
        }

        if ($rows === []) {
            $io->success('Ninguna resolución se contradice a sí misma.');

            return Command::SUCCESS;
        }

        $headers = ['Referencia', 'Fuente', 'Contradicción', 'Veredicto'];
        if ($apply) {
            $headers[] = 'Razonamiento del modelo';
        }
        $io->table($headers, $rows);

        if (!$apply) {
            $io->warning(sprintf(
                '%d resoluciones se contradicen. DRY-RUN: no se ha escrito ni llamado al modelo. Repite con --apply.',
                $touched,
            ));

            return Command::SUCCESS;
        }

        // Through the ORM, so ResolutionIndexListener reindexes each corrected row.
        $this->entityManager->flush();

        $io->success($async
            ? sprintf('%d desempates despachados a los workers (transporte analysis).', $touched)
            : sprintf('%d resoluciones corregidas por el modelo (el resto, confirmadas).', $touched));
        $io->note('Ejecuta `fos:elastica:populate --index=resolutions` si el índice no está al día.');

        return Command::SUCCESS;
    }
}
