<?php

declare(strict_types=1);

namespace App\Service\Team;

/**
 * Lanzada al superar el tope de miembros del plan en servidor.
 */
final class MemberCapExceededException extends \RuntimeException
{
    public function __construct(
        private readonly int $max,
        private readonly string $plan,
    ) {
        parent::__construct(sprintf(
            'El plan %s admite hasta %d miembros.',
            $plan,
            $max,
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
}
