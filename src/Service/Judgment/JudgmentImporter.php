<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\DTO\JudgmentData;
use App\Entity\Judgment;
use App\Repository\JudgmentRepository;
use App\Repository\ResolutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists what a JudgmentReaderInterface produced: upsert by (referenceNumber, source), wire
 * the instancia→apelación→casación chain, and link each judgment to the CTBG resolutions it
 * rules on.
 *
 * The MATCH RATE is a first-class output, not a curiosity: the CTBG corpus in the database
 * barely covers 2015-2017, where most litigation lives, so many refs will not match TODAY.
 * They are stored canonically on the judgment (`challengedResolutionRefs`) and `relink()` is
 * re-runnable — every import of older resolutions shrinks the backlog.
 */
final class JudgmentImporter
{
    /** Refs are CTBG state-council references; other sources have no recursos listing. */
    private const RESOLUTION_SOURCE = 'CTBG';

    public function __construct(
        private readonly JudgmentRepository $judgments,
        private readonly ResolutionRepository $resolutions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResolutionJudicialStatusUpdater $statusUpdater,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<JudgmentData> $items in chain order (reviewed judgment always first)
     *
     * @return array{created: int, updated: int, linked: int, refsTotal: int, refsMatched: int, unparsed: int}
     */
    public function import(array $items, bool $dryRun = false): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'linked' => 0, 'refsTotal' => 0, 'refsMatched' => 0, 'unparsed' => 0];

        /** @var array<string, Judgment> $inBatch reference => entity, for chaining within the run */
        $inBatch = [];

        foreach ($items as $data) {
            $judgment = $this->judgments->findByReference($data->referenceNumber, $data->source)
                ?? $inBatch[$data->referenceNumber]
                ?? null;

            if ($judgment === null) {
                $judgment = new Judgment();
                $judgment->setReferenceNumber($data->referenceNumber);
                $judgment->setSource($data->source);
                ++$stats['created'];
            } else {
                ++$stats['updated'];
            }

            $inBatch[$data->referenceNumber] = $judgment;

            // The listing owns the case facts; the analyzer (later phase) owns what it reads
            // out of the PDF (ecli, outcome, stance) and is never overwritten from here.
            $judgment->setCourt($data->court)
                ->setCourtNumber($data->courtNumber)
                ->setInstance($data->instance)
                ->setJudgmentNumber($data->judgmentNumber)
                ->setSubject($data->subject)
                ->setAppellant($data->appellant)
                ->setAppellantType($data->appellantType)
                ->setRepresentation($data->representation)
                ->setSourceUrl($data->sourceUrl)
                ->setNeedsBrowser($data->needsBrowser)
                ->setIsFinal($data->isFinal)
                ->setFinalDate($data->finalDate)
                ->setChallengedResolutionRefs($data->challengedResolutionRefs !== [] ? $data->challengedResolutionRefs : null);

            $metadata = array_merge($judgment->getSourceMetadata(), $data->sourceMetadata);
            if ($data->unparsedRefs !== []) {
                $metadata['unmatchedRefs'] = $data->unparsedRefs;
                $stats['unparsed'] += count($data->unparsedRefs);
            }
            $judgment->setSourceMetadata($metadata !== [] ? $metadata : null);

            if ($data->reviewedReferenceNumber !== null) {
                $reviewed = $inBatch[$data->reviewedReferenceNumber]
                    ?? $this->judgments->findByReference($data->reviewedReferenceNumber, $data->source);
                $judgment->setReviewedJudgment($reviewed);
            }

            $stats['refsTotal'] += count($data->challengedResolutionRefs);
            $stats['refsMatched'] += $this->link($judgment);
            $stats['linked'] += count($judgment->getResolutions());

            if (!$dryRun) {
                $this->entityManager->persist($judgment);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            // AFTER the flush: findByResolutionIds() reads judgment_resolution from the database,
            // so refreshing before the join rows exist would classify against an empty chain.
            $this->statusUpdater->refreshResolutions(self::resolutionsOf($inBatch));
        }

        return $stats;
    }

    /**
     * Re-run resolution linking over every judgment that stored refs but matched nothing —
     * the operation to run after importing older CTBG years.
     *
     * @return array{judgments: int, nowLinked: int}
     */
    public function relink(): array
    {
        $pending = $this->judgments->findUnlinkedWithRefs();
        $nowLinked = 0;

        foreach ($pending as $judgment) {
            if ($this->link($judgment) > 0) {
                ++$nowLinked;
            }
        }

        $this->entityManager->flush();

        $this->statusUpdater->refreshResolutions(self::resolutionsOf($pending));

        return ['judgments' => count($pending), 'nowLinked' => $nowLinked];
    }

    /**
     * Every resolution touched by a batch of judgments, deduplicated — the set whose denormalized
     * status may have just changed.
     *
     * @param iterable<Judgment> $judgments
     *
     * @return list<Resolution>
     */
    private static function resolutionsOf(iterable $judgments): array
    {
        $byId = [];

        foreach ($judgments as $judgment) {
            foreach ($judgment->getResolutions() as $resolution) {
                $byId[(string) $resolution->getId()] = $resolution;
            }
        }

        return array_values($byId);
    }

    /** @return int refs that matched an existing Resolution row */
    private function link(Judgment $judgment): int
    {
        $matched = 0;

        foreach ($judgment->getChallengedResolutionRefs() as $ref) {
            $resolution = $this->resolutions->findOneBy([
                'referenceNumber' => $ref,
                'source' => self::RESOLUTION_SOURCE,
            ]);

            if ($resolution !== null) {
                $judgment->addResolution($resolution);
                ++$matched;
            } else {
                $this->logger->debug('Judgment ref without a matching resolution (yet)', [
                    'judgment' => $judgment->getReferenceNumber(),
                    'ref' => $ref,
                ]);
            }
        }

        return $matched;
    }
}
