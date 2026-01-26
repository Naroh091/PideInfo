<?php

namespace App\DTO;

final readonly class ComplaintDraft
{
    /**
     * @param array<int, CitedResolution> $citedResolutions
     * @param array<int, string> $citedCriteria
     */
    public function __construct(
        public string $content,
        public string $transparencyCouncil,
        public string $applicableLaw,
        public array $citedResolutions = [],
        public array $citedCriteria = [],
        public ?SuccessAnalysis $successAnalysis = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $citedResolutions = [];
        foreach (($data['citedResolutions'] ?? []) as $resolution) {
            $citedResolutions[] = CitedResolution::fromArray($resolution);
        }

        $successAnalysis = null;
        if (isset($data['successAnalysis'])) {
            $successAnalysis = SuccessAnalysis::fromArray($data['successAnalysis']);
        }

        return new self(
            content: $data['content'] ?? '',
            transparencyCouncil: $data['transparencyCouncil'] ?? '',
            applicableLaw: $data['applicableLaw'] ?? '',
            citedResolutions: $citedResolutions,
            citedCriteria: $data['citedCriteria'] ?? [],
            successAnalysis: $successAnalysis,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'transparencyCouncil' => $this->transparencyCouncil,
            'applicableLaw' => $this->applicableLaw,
            'citedResolutions' => array_map(fn($r) => $r->toArray(), $this->citedResolutions),
            'citedCriteria' => $this->citedCriteria,
            'successAnalysis' => $this->successAnalysis?->toArray(),
        ];
    }

    public function hasCitedResolutions(): bool
    {
        return !empty($this->citedResolutions);
    }

    public function hasCitedCriteria(): bool
    {
        return !empty($this->citedCriteria);
    }
}
