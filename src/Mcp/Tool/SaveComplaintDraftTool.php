<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\DTO\ComplaintDraft;
use App\Entity\User;
use App\Mcp\Dto\DocumentSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\TransparencyCouncilResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Persists the ephemeral complaint/alegation canvas (produced turn by turn via
 * draft_complaint_message) as a Document — the MCP analogue of the web
 * "Guardar". For complaints this upserts the existing draft Document
 * (idempotent re-save); for allegation responses it creates a new Document.
 */
#[McpTool(
    name: 'save_complaint_draft',
    description: 'Guarda como documento del expediente el borrador de reclamación (mode=complaint) o de respuesta a alegaciones (mode=alegation_response) que se ha ido redactando con draft_complaint_message. Necesario porque el lienzo es efímero hasta guardarlo.',
)]
final class SaveComplaintDraftTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly TransparencyCouncilResolver $councilResolver,
    ) {
    }

    /**
     * @param string      $requestId UUID de la solicitud.
     * @param string      $mode      'complaint' o 'alegation_response'.
     * @param string      $bodyHtml  Contenido final del documento (HTML para reclamación, texto para alegaciones).
     * @param string|null $title     Título opcional (informativo; el nombre del documento es fijo por tipo).
     */
    public function __invoke(
        string $requestId,
        string $mode,
        string $bodyHtml,
        ?string $title = null,
    ): DocumentSummary {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!in_array($mode, [ComplaintDraftGenerator::MODE_COMPLAINT, ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'complaint' or 'alegation_response'.");
        }

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $ar = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $ar) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($ar->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '') {
            throw new InvalidArgumentException('bodyHtml is required.');
        }

        $eligible = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? $this->complaintGenerator->canGenerateAlegationResponse($ar)
            : ($this->complaintGenerator->canGenerateComplaint($ar) || $ar->getComplaintDraftDocument() !== null);
        if (!$eligible) {
            throw new InvalidArgumentException("Mode '{$mode}' is not allowed for this request in its current state.");
        }

        $law = $ar->getApplicableLaw();
        $draft = new ComplaintDraft(
            content: $bodyHtml,
            transparencyCouncil: $this->councilResolver->forLaw($law),
            applicableLaw: $law->getName(),
        );

        $origin = ['origin' => 'mcp/' . $this->tokenContext->getClientId()];
        $document = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? $this->complaintGenerator->saveAlegationResponse($ar, $draft, $origin)
            : $this->complaintGenerator->saveComplaint($ar, $draft, $origin);

        return DocumentSummary::fromEntity($document);
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
