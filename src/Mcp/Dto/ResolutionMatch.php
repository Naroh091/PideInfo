<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * One resolution surfaced by search_resolutions. `agentArgument` is only present
 * for deep-reviewed (vetted-applicable) matches; it is null for the plain
 * semantic-search results and for the "related" fallback list.
 */
final readonly class ResolutionMatch
{
    /**
     * @param list<string> $keypoints
     */
    public function __construct(
        public string $reference,
        public ?string $date,
        public string $outcome,
        public ?string $publicBody,
        public ?string $complaintOrganism,
        public ?string $summary,
        public array $keypoints,
        public ?string $agentArgument,
        public ?float $score,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row as produced by ResolutionRetriever / ResolutionSearchPipeline
     */
    public static function fromRow(array $row): self
    {
        $keypoints = $row['keypoints'] ?? [];

        return new self(
            reference: (string) ($row['reference'] ?? '—'),
            date: isset($row['date']) ? (string) $row['date'] : null,
            outcome: (string) ($row['outcome'] ?? 'unknown'),
            publicBody: isset($row['publicBody']) ? (string) $row['publicBody'] : null,
            complaintOrganism: isset($row['complaintOrganism']) ? (string) $row['complaintOrganism'] : null,
            summary: isset($row['summary']) ? (string) $row['summary'] : null,
            keypoints: array_values(array_map('strval', \is_array($keypoints) ? $keypoints : [])),
            agentArgument: isset($row['agent_argument']) && $row['agent_argument'] !== '' ? (string) $row['agent_argument'] : null,
            score: isset($row['score']) ? (float) $row['score'] : null,
        );
    }
}
