<?php

namespace App\DTO;

final readonly class CitedResolution
{
    public function __construct(
        public string $reference,
        public ?string $date = null,
        public ?string $excerpt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reference: $data['reference'] ?? 'Unknown',
            date: $data['date'] ?? null,
            excerpt: $data['excerpt'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'date' => $this->date,
            'excerpt' => $this->excerpt,
        ];
    }
}
