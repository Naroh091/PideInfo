<?php

namespace App\Search;

use App\Entity\ComplaintOrganism;
use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;

/**
 * Structured (filter-based) resolution lookup for the AI tools — the counterpart to the
 * semantic `ResolutionRetriever`. Answers questions the vector search cannot: exact filters
 * by organism, outcome, invoked limit, inadmission cause, dates or time-to-resolve, with
 * real totals and per-outcome counts.
 *
 * Shared by the MCP tool and the agent tool so both expose the same contract. Runs on
 * `ResolutionSearchInterface`, so it hits Elasticsearch and silently falls back to Postgres.
 *
 * Throws \InvalidArgumentException with the allowed values when a filter value is unknown:
 * both callers surface that message to the model so it can self-correct.
 */
final readonly class ResolutionFilteredLookup
{
    public const MAX_RESULTS = 25;

    public function __construct(
        private ResolutionSearchInterface $resolutionSearch,
        private ComplaintOrganismRepository $organismRepository,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     page: int,
     *     totalPages: int,
     *     outcomeStats: array<string, int>,
     *     results: list<array<string, mixed>>,
     *     appliedFilters: array<string, string>,
     * }
     */
    public function search(
        string $query = '',
        string $organism = '',
        string $publicBody = '',
        string $outcome = '',
        string $keyword = '',
        string $invokedLimit = '',
        string $inadmissionCause = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $resolveTime = '',
        int $maxResults = 10,
        int $page = 1,
    ): array {
        $outcome = $this->validateChoice('outcome', $outcome, array_keys(Resolution::getOutcomeLabels()));
        $invokedLimit = $this->validateChoice('invokedLimit', $invokedLimit, array_keys(Resolution::getLimitLabels()));
        $inadmissionCause = $this->validateChoice('inadmissionCause', $inadmissionCause, array_keys(Resolution::getInadmissionCauseLabels()));
        $resolveTime = $this->validateChoice('resolveTime', $resolveTime, array_keys(ResolutionSearchQuery::RESOLVE_TIME_RANGES));
        $dateFrom = $this->validateDate('dateFrom', $dateFrom);
        $dateTo = $this->validateDate('dateTo', $dateTo);

        $resolvedOrganism = $organism !== '' ? $this->resolveOrganism($organism) : null;

        $searchQuery = ResolutionSearchQuery::fromArray([
            'search' => $query,
            'organism' => $resolvedOrganism?->getId()->toRfc4122() ?? '',
            'publicBody' => $publicBody,
            'outcome' => $outcome,
            'keyword' => $keyword,
            'limit' => $invokedLimit,
            'inadmissionCause' => $inadmissionCause,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'resolveTime' => $resolveTime,
        ], max(1, $page), max(1, min(self::MAX_RESULTS, $maxResults)));

        $result = $this->resolutionSearch->search($searchQuery);

        return [
            'total' => $result->total,
            'page' => $searchQuery->page,
            'totalPages' => max(1, min(
                (int) ceil($result->total / $searchQuery->perPage),
                ResolutionSearchQuery::maxPage($searchQuery->perPage),
            )),
            'outcomeStats' => $result->outcomeStats,
            'results' => array_map($this->summarize(...), $result->resolutions),
            'appliedFilters' => array_filter([
                'query' => $query,
                'organism' => $resolvedOrganism?->getShortName() ?? $resolvedOrganism?->getName(),
                'publicBody' => $publicBody,
                'outcome' => $outcome,
                'keyword' => $keyword,
                'invokedLimit' => $invokedLimit,
                'inadmissionCause' => $inadmissionCause,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'resolveTime' => $resolveTime,
            ], static fn (?string $v): bool => $v !== null && $v !== ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Resolution $resolution): array
    {
        return [
            'resolutionId' => (string) $resolution->getId(),
            'reference' => $resolution->getReferenceNumber(),
            'date' => $resolution->getResolutionDate()?->format('Y-m-d'),
            'outcome' => $resolution->getOutcome(),
            'outcomeLabel' => $resolution->getOutcomeLabel(),
            'organism' => $resolution->getComplaintOrganism()?->getShortName()
                ?? $resolution->getComplaintOrganism()?->getName(),
            'publicBody' => $resolution->getPublicBodyName(),
            'subject' => $resolution->getSubject(),
            'summary' => $resolution->getSummary(),
            'keypoints' => $resolution->getKeypoints() ?? [],
            'invokedLimits' => $resolution->getLimits() ?? [],
            'inadmissionCauses' => $resolution->getInadmissionCauses() ?? [],
            'daysToResolve' => $resolution->getDaysToResolve(),
            'sourceUrl' => $resolution->getSourceUrl(),
        ];
    }

    /**
     * Accepts a ComplaintOrganism short name or slug ("CTBG", "gaip"), case-insensitively.
     */
    private function resolveOrganism(string $value): ComplaintOrganism
    {
        $needle = mb_strtolower(trim($value));

        $known = [];
        foreach ($this->organismRepository->findAllOrdered() as $candidate) {
            foreach ([$candidate->getShortName(), $candidate->getSlug(), $candidate->getName()] as $alias) {
                if ($alias !== null && mb_strtolower($alias) === $needle) {
                    return $candidate;
                }
            }
            $known[] = $candidate->getShortName() ?? $candidate->getSlug();
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown organism "%s". Use one of the transparency-council short names: %s.',
            $value,
            implode(', ', array_filter($known)),
        ));
    }

    /**
     * @param list<string> $allowed
     */
    private function validateChoice(string $name, string $value, array $allowed): string
    {
        $value = trim($value);
        if ($value === '' || in_array($value, $allowed, true)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid %s "%s". Allowed values: %s.',
            $name,
            $value,
            implode(', ', $allowed),
        ));
    }

    private function validateDate(string $name, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Invalid %s "%s". Expected YYYY-MM-DD.', $name, $value));
        }

        return $value;
    }
}
