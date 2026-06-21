<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\User;
use App\Service\AI\Agent\AgentProgress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Agent tool: persists a GENERALIZABLE writing-style preference the user expressed,
 * onto the authenticated user, so every future generation applies it.
 *
 * Persisting via a tool (rather than a final-decision field) is deliberate: the model
 * calls it reliably mid tool-loop, and the preference is committed BEFORE the (often
 * long) final draft generation — so it survives even if that stream is interrupted.
 */
#[AsTool(
    name: 'save_user_preference',
    description: 'Guarda una preferencia de redacción GENERALIZABLE del usuario: un gusto de estilo que querrá en TODAS sus redacciones futuras (solicitudes, reclamaciones y respuestas a alegaciones), no una instrucción puntual de este documento. Llámala cuando el usuario exprese o pida un cambio de estilo generalizable, p. ej. "ve más al grano", "textos más cortos", "parafrasea las resoluciones en vez de citarlas". NO la llames para cambios específicos de este caso ("elimina el segundo argumento", "corrige esa fecha"). Si dudas de si es general o puntual, pregunta al usuario antes en vez de llamarla.',
)]
final class SaveUserPreferenceTool
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $preference Preferencia nueva, redactada en tercera persona, p. ej.
     *                           "Al usuario le gusta ir al grano y parafrasear las resoluciones originales".
     * @param string $replaces   Preferencia aprendida previa que ha quedado obsoleta porque el usuario la
     *                           contradijo y debe eliminarse (cópiala tal como aparece en "Estilo aprendido").
     *                           Déjalo vacío si no reemplaza a ninguna.
     */
    public function __invoke(string $preference, string $replaces = ''): string
    {
        $preference = trim($preference);
        $replaces = trim($replaces);

        if ($preference === '' && $replaces === '') {
            return 'No se ha indicado ninguna preferencia que guardar.';
        }

        $this->progress->step('Aprendiendo preferencia…', 'save_user_preference');

        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 'No hay usuario autenticado; no se ha guardado la preferencia.';
        }

        $changed = $user->applyLearnedPreferenceDeltas(
            $preference !== '' ? [$preference] : [],
            $replaces !== '' ? [$replaces] : [],
        );

        if (!$changed) {
            return 'La preferencia ya estaba registrada; no se ha cambiado nada.';
        }

        $this->entityManager->flush();

        $learned = $user->getLearnedPreferences();

        return "Preferencia guardada. Preferencias aprendidas actuales del usuario:\n- " . implode("\n- ", $learned);
    }
}
