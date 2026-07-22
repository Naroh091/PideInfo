<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Agent\AgentRequestContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Agent tool: edits the draft (title + body) of the ONE request the current
 * consult conversation is about, while it is still STATUS_PENDING.
 *
 * Hard gates, in order — none of them trusts the model's arguments:
 *  1. {@see AgentRequestContext} must hold an editable request id for this turn
 *     (the orchestrator only sets it on consult turns over a pending request),
 *     and `requestId` must match it exactly. A mismatch is refused loudly, not
 *     silently corrected: on a WRITE tool a wrong UUID means a confused model.
 *  2. The authenticated user must own the request.
 *  3. The request must still be a draft (STATUS_PENDING) at execution time —
 *     re-checked here, not only when the tool was offered.
 *
 * Persistence goes through {@see RequestDraftGenerator::applyDraft()}, the same
 * single path the /redactar canvas uses (channel-aware caps, HTML→plain,
 * REG expone/solicita ↔ description sync).
 */
#[AsTool(
    name: 'edit_request',
    description: 'Edita el borrador de la solicitud de ESTA conversación (título y/o cuerpo) directamente en el expediente. Solo funciona mientras la solicitud sigue en estado borrador y únicamente sobre la solicitud actual: cualquier otro ID se rechaza. Envía solo los campos que cambian; los vacíos se conservan.',
)]
final class EditRequestDraftTool
{
    public function __construct(
        private readonly AccessRequestRepository $requestRepository,
        private readonly RequestDraftGenerator $draftGenerator,
        private readonly AgentRequestContext $requestContext,
        private readonly Security $security,
        private readonly AgentProgress $progress,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $requestId UUID exacto de la solicitud de esta conversación (el «ID de la solicitud» indicado en el contexto). Cualquier otro ID será rechazado.
     * @param string $title     Nuevo título de la solicitud. Vacío = conservar el actual.
     * @param string $body      Nuevo cuerpo de la solicitud (solo canal portal/email). Vacío = conservar el actual.
     * @param string $expone    Nuevo bloque EXPONE (solo canal registro/REG). Vacío = conservar el actual.
     * @param string $solicita  Nuevo bloque SOLICITA (solo canal registro/REG). Vacío = conservar el actual.
     */
    public function __invoke(
        string $requestId,
        string $title = '',
        string $body = '',
        string $expone = '',
        string $solicita = '',
    ): string {
        $editableId = $this->requestContext->getEditableRequestId();
        if ($editableId === null) {
            return 'La edición del borrador no está disponible en esta conversación. No se ha cambiado nada.';
        }

        if (!Uuid::isValid($requestId) || strtolower($requestId) !== strtolower($editableId)) {
            return sprintf(
                'Solo puedes editar la solicitud de esta conversación (ID %s). No se ha cambiado nada.',
                $editableId,
            );
        }

        $ar = $this->requestRepository->find(Uuid::fromString($requestId));
        if (!$ar instanceof AccessRequest) {
            return 'No se ha encontrado la solicitud. No se ha cambiado nada.';
        }

        $user = $this->security->getUser();
        if (!$user instanceof User
            || $ar->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return 'No tienes acceso a esta solicitud. No se ha cambiado nada.';
        }

        if ($ar->getStatus() !== AccessRequest::STATUS_PENDING) {
            return 'La solicitud ya se ha enviado a la Administración: el borrador ya no se puede editar. No se ha cambiado nada.';
        }

        $title = trim($title);
        $body = trim($body);
        $expone = trim($expone);
        $solicita = trim($solicita);
        $isReg = $ar->getRegDestination() !== null;

        // Channel-aware "did the model actually send an applicable change?".
        $hasBodyChange = $isReg ? ($expone !== '' || $solicita !== '') : $body !== '';
        if ($title === '' && !$hasBodyChange) {
            if ($isReg && $body !== '') {
                return 'Esta solicitud va por registro (REG): su cuerpo son los campos `expone` y `solicita`, no `body`. No se ha cambiado nada.';
            }
            if (!$isReg && ($expone !== '' || $solicita !== '')) {
                return 'Esta solicitud no va por registro: edita su cuerpo con el campo `body`. No se ha cambiado nada.';
            }

            return 'No has indicado ningún cambio: envía `title` y/o el cuerpo que quieras modificar.';
        }

        $this->progress->step('Editando el borrador de la solicitud…', 'edit_request');

        // Merge with the current values: applyDraft overwrites every field it
        // handles, so untouched fields must be re-fed with their current value.
        $draft = ['title' => $title !== '' ? $title : (string) $ar->getTitle()];
        if ($isReg) {
            $draft['expone'] = $expone !== '' ? $expone : (string) $ar->getExpone();
            $draft['solicita'] = $solicita !== '' ? $solicita : (string) $ar->getSolicita();
        } else {
            $draft['body_text'] = $body !== '' ? $body : (string) $ar->getDescription();
        }

        $applied = $this->draftGenerator->applyDraft($ar, $draft);
        $this->entityManager->flush();

        $lines = [];
        foreach ($applied as $field => $value) {
            $lines[] = sprintf('- %s: %s', $field, (string) $value);
        }

        return "Borrador actualizado correctamente. Valores aplicados:\n"
            . implode("\n", $lines)
            . "\n\nConfirma el cambio al usuario e indícale que recargue la ficha de la solicitud para ver el texto actualizado.";
    }
}
