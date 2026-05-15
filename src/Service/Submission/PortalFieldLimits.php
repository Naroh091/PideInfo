<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AgentTask;

/**
 * Field-level character limits enforced by each external submission portal.
 *
 * The agent will eventually paste these values into the portal's own form, so
 * PideInfo's "realizar" form must reject anything longer than the portal accepts.
 * Validation runs on the server (via Length constraints) and on the client
 * (via the character_counter Stimulus controller using attr.maxlength).
 *
 * Discovery details for each channel live in app/docs/transparencia_age_submission.md.
 */
final class PortalFieldLimits
{
    /**
     * Limits captured from the AGE wizard
     * (https://transparencia.sede.gob.es/procedimiento/formulario?idProc=133628&idAmb=...).
     * Step 2 — "Datos de solicitud":
     *   - Asunto              → dnt-input maxlength=255
     *   - Información solicita→ dnt-input type=textarea maxlength=3000
     * Step 1 — "Datos del solicitante":
     *   - "Nombre vía, número" → dnt-input maxlength=50
     */
    public const TRANSPARENCIA_AGE = [
        'title' => 255,
        'description' => 3000,
        'address_line' => 50,
    ];

    /**
     * REG step 2 ("Datos de solicitud") splits the body into two textareas
     * of max 4000 characters each (EXPONE / SOLICITA). The asunto field of
     * the REG portal caps at 80 characters — going over silently truncates
     * on submission, so we enforce it upstream.
     */
    public const REDSARA_REC = [
        'title' => 80,
        'expone' => 4000,
        'solicita' => 4000,
    ];

    /**
     * Returns the limits for a single channel (AgentTask::TYPE_SUBMIT_REQUEST_*),
     * or an empty array if the channel is unknown.
     *
     * @return array<string,int>
     */
    public static function forChannel(string $taskType): array
    {
        return match ($taskType) {
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL => self::TRANSPARENCIA_AGE,
            AgentTask::TYPE_SUBMIT_REQUEST_REG => self::REDSARA_REC,
            default => [],
        };
    }

    /**
     * Returns the most restrictive limit per field across the given channels.
     * If no channel declares a limit for a field, the field is omitted from
     * the result (i.e. no constraint).
     *
     * Example: intersect(['submit_request_transparencia', 'submit_request_reg'])
     * returns the AGE limits because REG declares none.
     *
     * @param list<string> $taskTypes
     * @return array<string,int>
     */
    public static function intersect(array $taskTypes): array
    {
        $combined = [];
        foreach ($taskTypes as $type) {
            foreach (self::forChannel($type) as $field => $limit) {
                $combined[$field] = isset($combined[$field])
                    ? min($combined[$field], $limit)
                    : $limit;
            }
        }
        return $combined;
    }
}
