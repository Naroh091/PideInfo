<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Helpers for the generated-PDF templates.
 */
final class PdfContentExtension extends AbstractExtension
{
    /**
     * Matches a leading "Resumen de la reclamación" section header, as the
     * drafting prompts instruct the model to open the document. Tolerates
     * attributes, spacing and both accented and entity-encoded forms.
     */
    private const LEADING_SUMMARY_H1 = '/^\s*<h1[^>]*>\s*Resumen\s+de\s+la\s+reclamaci(?:ó|&oacute;|&#243;)n\s*<\/h1>\s*/iu';

    public function getFilters(): array
    {
        return [
            new TwigFilter('pdf_complaint_parts', $this->splitComplaintParts(...)),
        ];
    }

    /**
     * The PDF stationery opens complaints with a centred document heading
     * («Reclamación en materia de acceso a información pública») instead of
     * the drafted "Resumen de la reclamación" section header. The same
     * download route also serves alegaciones and other canvas documents, so
     * the header is only swapped when the content actually starts with it;
     * anything else passes through untouched.
     *
     * @return array{heading: bool, html: string}
     */
    public function splitComplaintParts(string $html): array
    {
        if (preg_match(self::LEADING_SUMMARY_H1, $html)) {
            return [
                'heading' => true,
                'html' => preg_replace(self::LEADING_SUMMARY_H1, '', $html, 1),
            ];
        }

        return ['heading' => false, 'html' => $html];
    }
}
