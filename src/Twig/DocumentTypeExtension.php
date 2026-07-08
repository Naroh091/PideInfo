<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\DocumentType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the DocumentType catalogue to Twig so the manual reclassification
 * <select> can be built from the single source of truth (the enum), grouped by
 * phase (solicitud vs. reclamación). Unprocessed is excluded — it must not be
 * assignable by hand.
 */
final class DocumentTypeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_type_groups', [$this, 'groups']),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     *   Keyed by phase label, each value a list of {value, label}.
     */
    public function groups(): array
    {
        $groups = [
            'Fase de solicitud' => [],
            'Fase de reclamación' => [],
        ];

        foreach (DocumentType::cases() as $case) {
            if ($case === DocumentType::Unprocessed) {
                continue;
            }
            $phase = $case->isComplaintRelated() ? 'Fase de reclamación' : 'Fase de solicitud';
            $groups[$phase][] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $groups;
    }
}
