<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Repository\ApplicableLawRepository;

/**
 * Picks the most natural ApplicableLaw for a given PublicBody. The rule is:
 * if the body belongs to an autonomous community, use that community's law;
 * otherwise (state-level or unknown) fall back to the state-level Ley 19/2013.
 *
 * Today this rule is duplicated inline in ProcessDocumentHandler and
 * CreateAccessRequestTool. Centralising it here so the new "realizar" flow
 * can reuse it without copying the if/else again.
 */
final class ApplicableLawResolver
{
    public function __construct(
        private readonly ApplicableLawRepository $applicableLawRepository,
    ) {
    }

    public function resolveFor(PublicBody $body): ?ApplicableLaw
    {
        $community = $body->getAutonomousCommunity();
        if ($community !== null) {
            $law = $this->applicableLawRepository->findByAutonomousCommunity($community);
            if ($law !== null) {
                return $law;
            }
        }

        return $this->applicableLawRepository->findStateLaw();
    }

    /**
     * Human-friendly summary of the response deadline for a given law,
     * useful in the picker preview card. Returns e.g. "1 mes (silencio negativo)"
     * or "20 días hábiles (silencio positivo)".
     */
    public function deadlineLabel(ApplicableLaw $law): string
    {
        $value = $law->getResponseDeadlineValue();
        $unit = $law->getResponseDeadlineUnit();

        $unitLabel = match ($unit) {
            'months' => $value === 1 ? 'mes' : 'meses',
            'days' => 'días naturales',
            'business_days' => 'días hábiles',
            default => $unit,
        };

        $silence = $law->isSilenceIsPositive() ? 'silencio positivo' : 'silencio negativo';

        return sprintf('%d %s (%s)', $value, $unitLabel, $silence);
    }
}
