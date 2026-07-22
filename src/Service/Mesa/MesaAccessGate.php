<?php

declare(strict_types=1);

namespace App\Service\Mesa;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Puerta de acceso de la mesa de resoluciones (/mesa-resoluciones).
 *
 * No es autenticación de usuarios: es una contraseña compartida que se reparte
 * al equipo del Consejo, configurada en la env MESA_PASSWORDS como lista
 * separada por comas ("CTBG,otra-clave"). Superarla marca la sesión y nada más.
 * Sin contraseñas configuradas la puerta falla cerrada: nadie entra.
 */
final class MesaAccessGate
{
    private const SESSION_KEY = 'mesa_resoluciones_granted';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $passwords,
    ) {
    }

    public function isGranted(): bool
    {
        try {
            return $this->requestStack->getSession()->get(self::SESSION_KEY) === true;
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * Comprueba la contraseña y, si es válida, concede el acceso a la sesión.
     */
    public function attempt(string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }

        $granted = false;
        foreach ($this->allowedPasswords() as $allowed) {
            // hash_equals en todas las candidatas: tiempo constante también en la longitud de la lista.
            if (hash_equals($allowed, $password)) {
                $granted = true;
            }
        }

        if ($granted) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, true);
        }

        return $granted;
    }

    public function revoke(): void
    {
        try {
            $this->requestStack->getSession()->remove(self::SESSION_KEY);
        } catch (\LogicException) {
        }
    }

    /** @return list<string> */
    public function allowedPasswords(): array
    {
        $parsed = array_values(array_filter(array_map('trim', explode(',', $this->passwords))));

        return $parsed;
    }
}
