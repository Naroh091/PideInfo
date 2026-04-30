<?php

namespace App\DTO;

/**
 * Compact case-strength assessment surfaced in the complaint workspace.
 *
 * `summary`, `strengths` and `weaknesses` carry trusted HTML restricted to
 * `<b>` and `<i>` tags (sanitization happens at the SuccessAnalyzer boundary).
 * Total payload is bounded to keep the side panel readable and the model
 * outputs disciplined.
 */
final readonly class SuccessAnalysis
{
    public const SCHEMA_VERSION = 'v2';

    public function __construct(
        public int $percentage,
        public string $summary,
        public string $strengths,
        public string $weaknesses,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Tolerate the legacy v1 payload (probability/reasoning/strengths[]/weaknesses[])
        // so cached analyses written before the schema bump still render.
        $percentage = (int) ($data['percentage'] ?? $data['probability'] ?? 0);
        $summary = (string) ($data['summary'] ?? $data['reasoning'] ?? '');

        $strengths = $data['strengths'] ?? '';
        if (is_array($strengths)) {
            $strengths = self::bulletsToHtml($strengths);
        }

        $weaknesses = $data['weaknesses'] ?? '';
        if (is_array($weaknesses)) {
            $weaknesses = self::bulletsToHtml($weaknesses);
        }

        return new self(
            percentage: max(0, min(100, $percentage)),
            summary: (string) $summary,
            strengths: (string) $strengths,
            weaknesses: (string) $weaknesses,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'percentage' => $this->percentage,
            'summary' => $this->summary,
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
        ];
    }

    public function getProbabilityLabel(): string
    {
        return match (true) {
            $this->percentage >= 80 => 'Muy alta',
            $this->percentage >= 60 => 'Alta',
            $this->percentage >= 40 => 'Media',
            $this->percentage >= 20 => 'Baja',
            default => 'Muy baja',
        };
    }

    /**
     * @param array<int, string> $items
     */
    private static function bulletsToHtml(array $items): string
    {
        $clean = array_filter(array_map(static fn ($x) => trim((string) $x), $items));
        if ($clean === []) {
            return '';
        }

        return '<ul><li>' . implode('</li><li>', $clean) . '</li></ul>';
    }
}
