<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\ResolutionDateExtractor;
use App\Service\Resolution\ResolutionProcessingTrait;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds resolutions whose stored text is INCOMPLETE, and rebuilds them from the source PDF.
 *
 * What we store as `Resolution::$fullText` is not the extracted text: it is the HTML an LLM
 * rewrote from it. When that generation stops mid-stream we save a mutilated document, and since
 * the raw text is never kept, **the end of the resolution is gone from the database for good** —
 * and the end is where the fallo lives.
 *
 * R/0701/2018 (BOSCO) is the case that exposed it: 15,301 chars ending on an open `<blockquote>`,
 * no dispositivo at all. Its outcome could only ever be a guess, and the agent quoting it never
 * sees what the Council actually decided. ResolutionAnalyzer::keepOrDegrade() now refuses to store
 * such a text; this command repairs the ones stored before that guard existed.
 *
 * Detection is deterministic and cheap (no LLM): see self::isTruncated(). Repair re-downloads the
 * PDF from sourceUrl, re-extracts the text, and re-analyses — so the outcome is recomputed from the
 * COMPLETE document, with the fallo in front of the model.
 */
#[AsCommand(
    name: 'app:resolutions:reprocess-truncated',
    description: 'Detecta resoluciones cuyo texto quedó truncado y las reconstruye desde el PDF de origen',
)]
final class ReprocessTruncatedResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    /**
     * A resolution is never this short. Below it, the text cannot contain antecedentes,
     * fundamentos AND a dispositivo — something was lost.
     */
    private const MIN_PLAUSIBLE_CHARS = 1_500;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ResolutionDateExtractor $dateExtractor,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Reconstruye de verdad (por defecto: solo lista)')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Una resolución concreta, por su referencia')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filtra por fuente (CTBG, GAIP…)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de resoluciones a tocar')
            ->addOption('vision', null, InputOption::VALUE_NONE, 'Fuerza transcripción por visión al re-extraer')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Despacha la reconstrucción a los workers en vez de hacerla inline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $vision = (bool) $input->getOption('vision');
        $async = (bool) $input->getOption('async');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;

        $qb = $this->resolutionRepository->createQueryBuilder('r')->orderBy('r.referenceNumber', 'ASC');

        if ($input->getOption('reference') !== null) {
            $qb->andWhere('r.referenceNumber = :ref')->setParameter('ref', $input->getOption('reference'));
        }
        if ($input->getOption('source') !== null) {
            $qb->andWhere('r.source = :src')->setParameter('src', $input->getOption('source'));
        }
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $rows = [];
        $repaired = 0;
        $unrepairable = 0;
        $seen = 0;

        foreach ($qb->getQuery()->toIterable() as $resolution) {
            /** @var Resolution $resolution */
            $reason = self::truncationReason($resolution);

            // 45k resolutions carrying their fullText do not fit in the UnitOfWork. Detaching the
            // ones we are not going to touch keeps the scan flat in memory. (Only the untouched
            // ones: clearing an entity we are about to write would silently drop the write.)
            if (++$seen % 200 === 0 && $reason === null) {
                $this->entityManager->clear();
            }

            if ($reason === null) {
                continue;
            }

            $url = $resolution->getSourceUrl();

            if (!$apply) {
                $rows[] = [
                    $resolution->getReferenceNumber(),
                    $resolution->getSource(),
                    $reason,
                    mb_strlen($resolution->getFullText()) . ' chars',
                    $url !== null ? 'sí' : 'NO (irreparable)',
                ];
                continue;
            }

            if ($url === null) {
                // No source to rebuild from. Say so — leaving it silently broken is how this got here.
                ++$unrepairable;
                $io->warning(sprintf('%s: truncada y SIN sourceUrl: no hay de dónde recuperarla.', $resolution->getReferenceNumber()));
                continue;
            }

            if ($async) {
                // forceDownload: the text is NOT empty (it is corrupt), so without it the handler
                // would skip the download and re-analyse exactly the same corruption.
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $resolution->getId(),
                    forceAnalysis: true,
                    forceDownload: true,
                    forceVision: $vision,
                ));
                ++$repaired;
                continue;
            }

            $io->section($resolution->getReferenceNumber() . ' — ' . $reason);
            $before = mb_strlen($resolution->getFullText());
            $beforeOutcome = $resolution->getOutcome();

            // Re-download, re-extract, and re-analyse from the COMPLETE text. The analyzer's outcome
            // gate (and its tie-break) now run with the fallo actually present.
            $this->downloadAndProcessPdf($resolution, $url, $io, $vision);
            $this->analyzeResolution($resolution, $io);
            $this->entityManager->flush();

            $after = mb_strlen($resolution->getFullText());
            $io->text(sprintf(
                'texto: %d → %d chars · outcome: %s → %s',
                $before,
                $after,
                $beforeOutcome,
                $resolution->getOutcome(),
            ));

            ++$repaired;
        }

        if (!$apply) {
            if ($rows === []) {
                $io->success('Ninguna resolución con el texto truncado.');

                return Command::SUCCESS;
            }

            $io->table(['Referencia', 'Fuente', 'Motivo', 'Texto', '¿Tiene origen?'], $rows);
            $io->warning(sprintf('%d resoluciones truncadas. DRY-RUN: no se ha tocado nada. Repite con --apply.', count($rows)));

            return Command::SUCCESS;
        }

        $io->success($async
            ? sprintf('%d despachadas a los workers (transporte async).', $repaired)
            : sprintf('%d reconstruidas desde el PDF de origen.', $repaired));
        if ($unrepairable > 0) {
            $io->warning(sprintf('%d truncadas sin sourceUrl: su texto no es recuperable.', $unrepairable));
        }
        $io->note('Ejecuta `fos:elastica:populate --index=resolutions` para reindexar.');

        return Command::SUCCESS;
    }

    /**
     * Why we believe this text is incomplete — or null when it looks whole.
     *
     * Both signals are things a complete document cannot do. Deliberately NOT used: "the text has
     * no RESUELVE". The wording of a dispositivo varies by council and by language (RESOL, ACORDA,
     * «Per tot això»), so that test flags a quarter of the corpus and means nothing.
     */
    public static function truncationReason(Resolution $resolution): ?string
    {
        $text = trim($resolution->getFullText());

        if ($text === '') {
            return null; // Never analysed; that is the --missing-pdf backlog, not truncation.
        }

        // The reformatting stopped mid-element: no formatter ends a document on an opening tag.
        if (preg_match('/<(p|blockquote|li|ol|ul|strong|em|h[1-6])>$/', $text) === 1) {
            return 'termina en etiqueta abierta';
        }

        if (mb_strlen(self::prose($text)) < self::MIN_PLAUSIBLE_CHARS) {
            return sprintf('demasiado corta (%d chars)', mb_strlen(self::prose($text)));
        }

        return null;
    }

    /**
     * The readable length of a stored text, HTML or not.
     *
     * strip_tags() may NOT be applied blindly here. Only ~7,300 rows hold reformatted HTML; the
     * rest are raw OCR text, and OCR sprinkles stray `<` and `>` through it («escritos d<? 19 de
     * agosto», «de ><aneiro»). strip_tags then swallows everything between them: one CTG
     * resolution of 38,954 characters collapses to 255, and a perfectly complete document gets
     * flagged as truncated. A first version of this command reported 471 victims that way — almost
     * all of them healthy.
     */
    private static function prose(string $text): string
    {
        $isHtml = preg_match('/<\/(p|blockquote|li|ol|ul|h[1-6])>/i', $text) === 1;

        return $isHtml ? trim(html_entity_decode(strip_tags($text))) : $text;
    }
}
