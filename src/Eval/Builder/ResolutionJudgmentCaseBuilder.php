<?php

declare(strict_types=1);

namespace App\Eval\Builder;

use App\Entity\Judgment;
use App\Eval\EvalCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ground truth for the JUDGMENTS target, from the same judgment↔resolution M2M
 * as JudgmentCrossCaseBuilder but in the inverse direction: a challenged
 * resolution's subject describes the dispute, so it is a natural query for
 * which the judgments that reviewed it are relevant by construction.
 */
final class ResolutionJudgmentCaseBuilder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, EvalCase> keyed by case id */
    public function build(int $limit = 0): array
    {
        // 425 judgments: iterating the owning side and grouping client-side is fine.
        $byResolution = [];
        /** @var Judgment $judgment */
        foreach ($this->em->createQueryBuilder()->select('j')->from(Judgment::class, 'j')->getQuery()->toIterable() as $judgment) {
            foreach ($judgment->getResolutions() as $resolution) {
                $key = $resolution->getId()->toRfc4122();
                $byResolution[$key]['resolution'] = $resolution;
                $byResolution[$key]['judgments'][] = $judgment->getId()->toRfc4122();
            }
        }

        $cases = [];
        foreach ($byResolution as $entry) {
            $resolution = $entry['resolution'];
            $query = trim((string) ($resolution->getSubject() ?? ''));
            if ($query === '') {
                $query = trim(mb_substr((string) $resolution->getSummary(), 0, 300));
            }
            if ($query === '') {
                continue;
            }

            $id = EvalCase::makeId('relations', $query);
            $cases[$id] = new EvalCase(
                id: $id,
                query: $query,
                relevant: array_values(array_unique($entry['judgments'])),
                source: 'relations',
                // Judgments filter by transparency stance, not outcome; the eval
                // path for this target ignores this field (kept for YAML shape).
                outcomes: EvalCase::ALL_OUTCOMES,
                meta: array_filter([
                    'resolution' => $resolution->getReferenceNumber(),
                    'graded' => 'derived',
                ]),
            );

            if ($limit > 0 && count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
    }
}
