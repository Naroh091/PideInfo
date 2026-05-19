<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\RegDestinationRepository;

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
    /**
     * Aliases for CCAA names that appear in the DIR3 / REG export differently
     * than `autonomous_community.name`. Mirror of the importer's table for
     * the cases we've actually observed in production data — the importer
     * canonicalises the body anchor, but `RegDestination.comunidad` keeps
     * the raw label, so we apply the same normalisation here.
     *
     * Keys are normalised (lowercase, accents stripped) — see {@see normalize()}.
     */
    private const COMUNIDAD_ALIASES = [
        'castilla la mancha' => 'Castilla-La Mancha',
        'castilla y leon' => 'Castilla y León',
        'comunidad valenciana' => 'Comunitat Valenciana',
        'valenciana' => 'Comunitat Valenciana',
        'madrid' => 'Comunidad de Madrid',
        'navarra' => 'Comunidad Foral de Navarra',
        'islas baleares' => 'Illes Balears',
        'baleares' => 'Illes Balears',
        'pais vasco' => 'País Vasco',
        'asturias' => 'Principado de Asturias',
        'murcia' => 'Región de Murcia',
        'region de murcia' => 'Región de Murcia',
        'ciudad autonoma de ceuta' => 'Ceuta',
        'ciudad autonoma de melilla' => 'Melilla',
    ];

    public function __construct(
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly AutonomousCommunityRepository $autonomousCommunityRepository,
    ) {
    }

    public function resolveFor(PublicBody $body): ?ApplicableLaw
    {
        $community = $body->getAutonomousCommunity()
            ?? $this->deriveCommunityFromRegDestinations($body);

        if ($community !== null) {
            $law = $this->applicableLawRepository->findByAutonomousCommunity($community);
            if ($law !== null) {
                return $law;
            }
        }

        return $this->applicableLawRepository->findStateLaw();
    }

    /**
     * Safety net for legacy `PublicBody` rows that pre-dated the REG import
     * and never had `autonomous_community_id` seeded (≈27% of `level=local`
     * bodies). When every linked `RegDestination` resolves to the same
     * AutonomousCommunity we use it; if any unit disagrees we abstain so we
     * never mis-attribute a cross-territorial body.
     *
     * State bodies are excluded up front: a body reachable through a
     * state-level registry unit is an `Administración del Estado` and is
     * governed by the state-level Ley 19/2013 regardless of where its
     * registry offices physically sit. `RegDestination.comunidad` records
     * office geography, not legal jurisdiction — for a ministry headquartered
     * in Madrid every unit would read "Comunidad de Madrid" and wrongly pull
     * in the regional law. We abstain so the caller falls back to state law.
     */
    private function deriveCommunityFromRegDestinations(PublicBody $body): ?AutonomousCommunity
    {
        if ($this->regDestinationRepository->bodyHasStateLevelDestination($body)) {
            return null;
        }

        $candidates = [];
        foreach ($this->regDestinationRepository->findDistinctComunidades($body) as $raw) {
            $resolved = $this->resolveCommunityName($raw);
            if ($resolved === null) {
                continue;
            }
            $candidates[$resolved->getId()->toRfc4122()] = $resolved;
        }
        if (count($candidates) !== 1) {
            return null;
        }
        return array_values($candidates)[0];
    }

    private function resolveCommunityName(string $raw): ?AutonomousCommunity
    {
        $key = self::normalize($raw);
        if ($key === '') {
            return null;
        }
        $canonical = self::COMUNIDAD_ALIASES[$key] ?? trim($raw);
        return $this->autonomousCommunityRepository->findByName($canonical);
    }

    private static function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        if (\function_exists('iconv')) {
            $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
            if ($stripped !== false) {
                $lower = $stripped;
            }
        }
        // Collapse non-letter runs to single spaces so "Castilla-La Mancha"
        // and "Castilla La Mancha" both normalise to "castilla la mancha".
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $lower));
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
