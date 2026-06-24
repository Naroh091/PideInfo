<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Mcp\Dto\NextActionSuggestion;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Complaint\ComplaintGenerator;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Suggests the next actionable step(s) for a request and which MCP tool to call,
 * based on its current state. Makes an external agent proactive without having
 * to infer the request/complaint workflow itself.
 */
#[McpTool(
    name: 'suggest_next_action',
    description: 'Dada una solicitud, sugiere el siguiente paso accionable (redactar, enviar, reclamar, responder alegaciones, hacer seguimiento…) y qué tool MCP usar, según su estado actual. Útil para guiar al usuario sin tener que deducir el flujo.',
)]
final class SuggestNextActionTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ComplaintGenerator $complaintGenerator,
    ) {
    }

    /**
     * @param string $requestId UUID de la solicitud.
     */
    public function __invoke(string $requestId): NextActionSuggestion
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        [$summary, $suggestions] = $this->evaluate($request);

        return new NextActionSuggestion(
            requestId: $request->getId()->toRfc4122(),
            status: $request->getStatus(),
            statusLabel: $request->getStatusLabel(),
            summary: $summary,
            suggestions: $suggestions,
        );
    }

    /**
     * @return array{0: string, 1: list<array{action: string, label: string, toolName: string, reason: string, available: bool}>}
     */
    private function evaluate(AccessRequest $request): array
    {
        $suggestions = [];

        // Draft not yet sent.
        if ($request->getStatus() === AccessRequest::STATUS_PENDING) {
            $suggestions[] = $this->s('draft_request', 'Termina de redactar la solicitud', 'draft_request_message',
                'La solicitud está en borrador; afínala conversacionalmente.', true);
            $suggestions[] = $this->s('submit_request', 'Envía la solicitud', 'submit_request',
                'Cuando el borrador esté listo, despáchalo a la administración por su canal.', true);

            return ['La solicitud está en borrador (no enviada). Redáctala y luego envíala.', $suggestions];
        }

        // Already has a complaint in flight.
        if ($request->getComplaint() !== null) {
            $suggestions[] = $this->s('track_complaint', 'Haz seguimiento de la reclamación', 'get_submission_status',
                'Ya existe una reclamación; consulta el estado de su presentación o sus plazos.', true);

            if ($this->complaintGenerator->canGenerateAlegationResponse($request)) {
                $suggestions[] = $this->s('draft_alegation_response', 'Responde a las alegaciones', 'draft_complaint_message',
                    'La administración ha presentado alegaciones; redacta la respuesta (mode=alegation_response).', true);
            }

            return ['La solicitud ya tiene una reclamación en curso.', $suggestions];
        }

        // Eligible to complain.
        if ($this->complaintGenerator->canGenerateComplaint($request)) {
            $suggestions[] = $this->s('draft_complaint', 'Redacta una reclamación', 'draft_complaint_message',
                'La solicitud es reclamable (denegada, en silencio o con plazo vencido): redacta la reclamación (mode=complaint).', true);
            $suggestions[] = $this->s('analyze_complaint', 'Estima la probabilidad de la reclamación', 'analyze_complaint_success',
                'Consulta la probabilidad de éxito antes de presentarla.', true);
            $suggestions[] = $this->s('present_complaint', 'Presenta la reclamación', 'present_complaint',
                'Tras guardarla con save_complaint_draft, despáchala al consejo de transparencia.', true);

            return ['La solicitud está en un estado reclamable: puedes presentar una reclamación.', $suggestions];
        }

        // Sent and awaiting an answer.
        if ($request->isDeadlinePassed()) {
            $suggestions[] = $this->s('await_or_complain', 'Revisa si procede reclamar', 'get_request_detail',
                'El plazo ha vencido; revisa el expediente para decidir si reclamar por silencio.', true);

            return ['El plazo de respuesta ha vencido. Revisa el expediente.', $suggestions];
        }

        $suggestions[] = $this->s('wait', 'Espera la respuesta de la administración', 'get_request_detail',
            sprintf('La solicitud está "%s"; aún no procede reclamar. Quedan %d días de plazo.', $request->getStatusLabel(), $request->getDaysUntilDeadline()), true);

        return ['La solicitud sigue su curso; aún no hay una acción de reclamación disponible.', $suggestions];
    }

    /**
     * @return array{action: string, label: string, toolName: string, reason: string, available: bool}
     */
    private function s(string $action, string $label, string $toolName, string $reason, bool $available): array
    {
        return ['action' => $action, 'label' => $label, 'toolName' => $toolName, 'reason' => $reason, 'available' => $available];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
