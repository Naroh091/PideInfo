<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\PublicBody;
use App\Entity\User;

/**
 * Decides which agent task type (= submission channel) a PublicBody is
 * reachable through. Implements the "prefer Portal de Transparencia over REG"
 * rule from the product spec: if the body has an AGE Portal de Transparencia
 * idAmb registered, it goes by AGE; otherwise it falls back to Red SARA REC.
 *
 * The criterion is the numeric `transparencyPortalAmbId`, not the informational
 * `transparencyPortalUrl`: the agent builds the wizard URL
 * (`/procedimiento/formulario?idProc=133628&idAmb={ID}`) from the idAmb, so a
 * body without one cannot be submitted through the portal even if it has a URL.
 *
 * Centralising this in one service keeps the form, the controller fan-out
 * and the Twig badge consistent.
 */
final class ChannelResolver
{
    public const BADGE_PORTAL = 'Portal Transparencia';
    public const BADGE_REG = 'REG';

    /**
     * Citizen-facing entry point of the Registro Electrónico General
     * (Red SARA REC) — the manual-submission fallback when a body has no AGE
     * Portal idAmb.
     */
    public const REG_PUBLIC_URL = 'https://rec.redsara.es/registro/action/are/acceso.do';

    private const PORTAL_WIZARD_URL = 'https://transparencia.sede.gob.es/procedimiento/formulario?idProc=133628&idAmb=%d';

    public function resolveTaskType(PublicBody $body): string
    {
        return $body->getTransparencyPortalAmbId() !== null
            ? AgentTask::TYPE_SUBMIT_REQUEST_PORTAL
            : AgentTask::TYPE_SUBMIT_REQUEST_REG;
    }

    public function badgeLabel(PublicBody $body): string
    {
        return $this->resolveTaskType($body) === AgentTask::TYPE_SUBMIT_REQUEST_PORTAL
            ? self::BADGE_PORTAL
            : self::BADGE_REG;
    }

    /**
     * Direct URL to the AGE Portal de Transparencia request wizard for this
     * body, or null when it has no idAmb (i.e. its channel is REG).
     */
    public function portalWizardUrl(PublicBody $body): ?string
    {
        $ambId = $body->getTransparencyPortalAmbId();

        return $ambId === null ? null : sprintf(self::PORTAL_WIZARD_URL, $ambId);
    }

    /**
     * @param iterable<PublicBody> $bodies
     * @return list<string> Distinct task types in the input order
     */
    public function distinctChannelsFor(iterable $bodies): array
    {
        $seen = [];
        foreach ($bodies as $body) {
            $type = $this->resolveTaskType($body);
            $seen[$type] = true;
        }
        return array_keys($seen);
    }

    /**
     * Diagnoses everything that prevents an AccessRequest from being handed to
     * the agent — channel-specific preconditions only (the controller still
     * runs its own validations for title/description not being empty etc.).
     *
     * Returns a list of machine-readable error codes (empty list = OK). The
     * controller turns them into UI messages and decides whether to render
     * the inline profile / unit picker, or fail the dispatch.
     *
     * @return list<string>
     */
    public function diagnoseDispatchPreconditions(AccessRequest $request, User $user): array
    {
        $errors = [];
        if ($this->resolveTaskType($request->getPublicBody()) === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
            if ($request->getRegDestination() === null) {
                $errors[] = 'reg_destination_required';
            }
            if (!$user->hasCompleteRegProfile()) {
                $errors[] = 'reg_profile_incomplete';
            }
            $body = $request->getRegBody();
            if ($body['expone'] === '' || $body['solicita'] === '') {
                $errors[] = 'reg_body_required';
            }
        }
        return $errors;
    }
}
