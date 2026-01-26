<?php

namespace App\DTO;

final readonly class SuccessAnalysis
{
    /**
     * @param array<int, string> $strengths
     * @param array<int, string> $weaknesses
     */
    public function __construct(
        public int $probability,
        public string $reasoning,
        public array $strengths = [],
        public array $weaknesses = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            probability: (int) ($data['probability'] ?? 0),
            reasoning: $data['reasoning'] ?? '',
            strengths: $data['strengths'] ?? [],
            weaknesses: $data['weaknesses'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'probability' => $this->probability,
            'reasoning' => $this->reasoning,
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
        ];
    }

    public function getProbabilityLabel(): string
    {
        return match (true) {
            $this->probability >= 80 => 'Muy alta',
            $this->probability >= 60 => 'Alta',
            $this->probability >= 40 => 'Media',
            $this->probability >= 20 => 'Baja',
            default => 'Muy baja',
        };
    }

    public function getProbabilityClass(): string
    {
        return match (true) {
            $this->probability >= 80 => 'text-green-600',
            $this->probability >= 60 => 'text-lime-600',
            $this->probability >= 40 => 'text-yellow-600',
            $this->probability >= 20 => 'text-orange-600',
            default => 'text-red-600',
        };
    }
}
