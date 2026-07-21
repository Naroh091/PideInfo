<?php

declare(strict_types=1);

namespace App\Eval\Builder;

use App\Entity\Judgment;
use App\Eval\EvalCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds ground-truth cases from the judgment↔resolution M2M cross: a judgment's
 * subject describes the dispute, so it is a natural query for which the
 * challenged resolution(s) are relevant by construction. Free, human-derived
 * relevance (the link comes from the court record, not from an LLM).
 */
final class JudgmentCrossCaseBuilder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, EvalCase> keyed by case id */
    public function build(int $limit = 0): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('j')
            ->from(Judgment::class, 'j')
            ->where('j.subject IS NOT NULL')
            ->andWhere("j.subject <> ''")
            ->orderBy('j.judgmentDate', 'DESC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $cases = [];
        /** @var Judgment $judgment */
        foreach ($qb->getQuery()->toIterable() as $judgment) {
            $relevant = [];
            $outcomes = [];
            foreach ($judgment->getResolutions() as $resolution) {
                $relevant[] = $resolution->getId()->toRfc4122();
                if ($resolution->getOutcome()) {
                    $outcomes[] = $resolution->getOutcome();
                }
            }
            if ($relevant === []) {
                continue;
            }

            $query = trim((string) $judgment->getSubject());
            $id = EvalCase::makeId('relations', $query);
            $cases[$id] = new EvalCase(
                id: $id,
                query: $query,
                relevant: $relevant,
                source: 'relations',
                // Wide filter: the production outcome filter for this query is unknown,
                // and narrowing to the ground truth's own outcomes would make the task
                // artificially easy. ALL keeps the pool honest without excluding the GT.
                outcomes: EvalCase::ALL_OUTCOMES,
                meta: array_filter([
                    'judgment' => $judgment->getEcli() ?? $judgment->getReferenceNumber(),
                    'resolutionOutcomes' => array_values(array_unique($outcomes)) ?: null,
                    'graded' => 'derived',
                ]),
            );
        }

        return $cases;
    }
}
