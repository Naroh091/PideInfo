<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Lanzada cuando un lote supera el cupo del plan efectivo. Lleva contexto para
 * responder 422 con pista de mejora (`upgradeUrl` la pone el controlador).
 */
final class QuotaExceededException extends \RuntimeException
{
    public function __construct(
        private readonly int $max,
        private readonly string $plan,
        private readonly int $requested,
    ) {
        parent::__construct(sprintf(
            'El plan %s admite lotes de hasta %d destinatarios y se pidieron %d.',
            $plan,
            $max,
            $requested,
        ));
    }

    public function getMax(): int
    {
        return $this->max;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function getRequested(): int
    {
        return $this->requested;
    }
}
