<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * One CTBG interpretive criterion vetted as applicable by search_criteria.
 * Cite with the literal form «Criterio CI/<nº>/<año>», always naming the CTBG
 * as the issuing body.
 */
final readonly class CriterionMatch
{
    /**
     * @param list<string> $keypoints
     */
    public function __construct(
        public string $reference,
        public ?int $year,
        public ?string $topic,
        public array $keypoints,
        public string $agentArgument,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row as produced by CriteriaSearchPipeline
     */
    public static function fromRow(array $row): self
    {
        $keypoints = $row['keypoints'] ?? [];

        return new self(
            reference: (string) ($row['canonical'] ?? $row['reference'] ?? '—'),
            year: isset($row['year']) ? (int) $row['year'] : null,
            topic: isset($row['topic']) && $row['topic'] !== '' ? (string) $row['topic'] : null,
            keypoints: array_values(array_map('strval', \is_array($keypoints) ? $keypoints : [])),
            agentArgument: (string) ($row['agent_argument'] ?? ''),
        );
    }
}
