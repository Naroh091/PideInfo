<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\DTO\ComplaintDraft;

/**
 * MCP-friendly summary of a generated complaint draft.
 */
final readonly class ComplaintDraftSummary
{
    /**
     * @param list<array{reference:string,date:?string,outcome:string,summary:?string}> $citedResolutions
     * @param list<string>                                                               $citedCriteria
     */
    public function __construct(
        public string $content,
        public string $transparencyCouncil,
        public string $applicableLaw,
        public array $citedResolutions,
        public array $citedCriteria,
    ) {
    }

    public static function fromDomain(ComplaintDraft $draft): self
    {
        $cited = [];
        foreach ($draft->citedResolutions as $resolution) {
            $cited[] = [
                'reference' => $resolution->reference,
                'date' => $resolution->date,
                'outcome' => '',
                'summary' => $resolution->excerpt,
            ];
        }

        return new self(
            content: $draft->content,
            transparencyCouncil: $draft->transparencyCouncil,
            applicableLaw: $draft->applicableLaw,
            citedResolutions: $cited,
            citedCriteria: array_values($draft->citedCriteria),
        );
    }
}
