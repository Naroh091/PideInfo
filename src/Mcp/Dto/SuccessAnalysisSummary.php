<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\DTO\SuccessAnalysis;

/**
 * MCP projection of a {@see SuccessAnalysis}: the success-probability feedback
 * shown in the web canvas after each draft change. `summary`/`strengths`/
 * `weaknesses` carry trusted HTML restricted to a small tag set (already
 * sanitized at the analyzer boundary).
 */
final readonly class SuccessAnalysisSummary
{
    public function __construct(
        public int $percentage,
        public string $label,
        public string $summary,
        public string $strengths,
        public string $weaknesses,
    ) {
    }

    public static function fromDomain(SuccessAnalysis $a): self
    {
        return new self(
            percentage: $a->percentage,
            label: $a->getProbabilityLabel(),
            summary: $a->summary,
            strengths: $a->strengths,
            weaknesses: $a->weaknesses,
        );
    }
}
