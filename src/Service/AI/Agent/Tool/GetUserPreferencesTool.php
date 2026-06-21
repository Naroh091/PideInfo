<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\User;
use App\Service\AI\Agent\AgentProgress;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Agent tool: returns the authenticated user's AI writing preferences.
 * These preferences should be applied when drafting any document.
 */
#[AsTool(
    name: 'get_user_preferences',
    description: 'Devuelve las preferencias de redacción guardadas por el usuario (tono, saludo, cierre, instrucciones adicionales). Úsalas siempre al generar o reescribir borradores.',
)]
final class GetUserPreferencesTool
{
    public function __construct(
        private readonly Security $security,
        private readonly AgentProgress $progress,
    ) {
    }

    public function __invoke(): string
    {
        $this->progress->step('Cargando preferencias de redacción del usuario…', 'get_user_preferences');

        /** @var User $user */
        $user = $this->security->getUser();

        $prefs = $user->getWritingPreferences();

        if ($prefs === []) {
            return 'El usuario no ha configurado preferencias de redacción.';
        }

        $labels = [
            'tone'       => 'Tono',
            'salutation' => 'Saludo/encabezado',
            'closing'    => 'Cierre',
            'notes'      => 'Instrucciones adicionales',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            if (!empty($prefs[$key])) {
                $lines[] = "- **{$label}:** {$prefs[$key]}";
            }
        }

        return "Preferencias de redacción del usuario:\n\n" . implode("\n", $lines);
    }
}
