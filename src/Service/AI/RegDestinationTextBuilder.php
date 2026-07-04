<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\RegDestination;

/**
 * Builds the searchable text that represents a {@see RegDestination} in the
 * semantic store. The text folds together the visible body (submissionTarget),
 * the intermediate organism, the unit name and the territorial/administrative
 * labels so a vague natural-language query ("servicio de salud de la Junta de
 * Andalucía") embeds close to the right destination ("Consejería de Salud ·
 * Andalucía"). Keep this deterministic and side-effect free — it is unit tested
 * and re-run on every (re)index.
 */
final class RegDestinationTextBuilder
{
    public function build(RegDestination $destination): string
    {
        $parts = [];

        // Visible body first: it carries the most recognisable name for a citizen.
        $parts[] = $destination->getSubmissionTarget()->getName();

        // Raíz, when distinct from the visible body (e.g. the ministry / junta).
        $raizName = $destination->getPublicBody()->getName();
        if ($raizName !== $destination->getSubmissionTarget()->getName()) {
            $parts[] = $raizName;
        }

        // Intermediate organism, only when it adds signal over the visible body.
        $intermediate = $destination->getIntermediateOrganismName();
        if ($intermediate !== null && $intermediate !== $destination->getSubmissionTarget()->getName()) {
            $parts[] = $intermediate;
        }

        // Unit + registry office names.
        $parts[] = $destination->getName();
        if ($destination->getOficinaName() !== null) {
            $parts[] = $destination->getOficinaName();
        }

        // Territorial / administrative labels.
        $parts[] = $destination->getComunidad();
        $parts[] = $destination->getProvincia();
        $parts[] = $destination->getNivelAdministracion();

        $clean = array_filter(
            array_map(static fn (?string $p): string => trim((string) $p), $parts),
            static fn (string $p): bool => $p !== '',
        );

        // De-dupe while preserving order (e.g. comunidad repeated in a name).
        $seen = [];
        $unique = [];
        foreach ($clean as $part) {
            $key = mb_strtolower($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $part;
        }

        return implode('. ', $unique);
    }
}
