<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Modelo elegido para UNA generación. En el agente, el turno entero (bucle de
 * tools + decisión final) se sirve con el mismo cliente: mezclar teacher y
 * modelo pequeño dentro del mismo turno produciría trazas que no representan
 * a ninguno de los dos.
 *
 * @see ModelRouter
 */
final readonly class ModelChoice
{
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    public function __construct(
        public CustomModelClient $client,
        public string $role,
    ) {
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }
}
