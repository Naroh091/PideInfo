<?php

declare(strict_types=1);

namespace App\Service\AccessRequest;

/**
 * Veredicto inmutable de SubmissionGuard sobre si una solicitud puede
 * despacharse a un canal de presentación.
 */
final readonly class SubmissionGuardDecision
{
    public function __construct(
        public bool $allowed,
        /** null | 'active_task' | 'uncertain_needs_confirmation' */
        public ?string $reason,
        /** idBorr a arrastrar al payload para que el agente reconcilie (solo transparencia). */
        public ?string $reconcileIdBorr,
    ) {}
}
