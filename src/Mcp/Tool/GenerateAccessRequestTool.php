<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Entity\User;
use App\Mcp\Dto\AccessRequestSummary;
use App\Repository\ApplicableLawRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\AccessRequest\Exception\RequestDraftNotGeneratedException;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\Submission\ApplicableLawResolver;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Generate (draft) an access request with server-side AI. Unlike
 * `create_access_request` — which records an already-filed request as SENT —
 * this produces a PENDING draft, redacted by AI, with the REG destination
 * attached, so it can be reviewed and sent later through the REG channel.
 */
#[McpTool(
    name: 'generate_access_request',
    description: 'Genera con IA el borrador de una solicitud de acceso (distinto de create_access_request, que registra una ya enviada). Crea la solicitud en estado PENDING, con el destino REG adjunto, lista para revisar y enviar después. Usa search_reg_destinations para obtener regDestinationId (id) y publicBodyId (submissionTargetId).',
)]
final class GenerateAccessRequestTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly DeadlineCalculator $deadlineCalculator,
        private readonly RequestDraftGenerator $requestDraftGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string      $publicBodyId    UUID del organismo destinatario (submissionTargetId de search_reg_destinations).
     * @param string      $prompt          Qué información quiere pedir el usuario, en lenguaje natural.
     * @param string|null $regDestinationId UUID de la unidad REG (id de search_reg_destinations). Obligatorio si el organismo tiene canal REG.
     * @param string|null $applicableLawId  UUID de la ApplicableLaw; si null se resuelve automáticamente.
     */
    public function __invoke(
        string $publicBodyId,
        string $prompt,
        ?string $regDestinationId = null,
        ?string $applicableLawId = null,
    ): AccessRequestSummary {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        $prompt = trim($prompt);
        if ('' === $prompt) {
            throw new InvalidArgumentException('prompt is required.');
        }

        if (!Uuid::isValid($publicBodyId)) {
            throw new InvalidArgumentException('publicBodyId must be a UUID.');
        }
        $publicBody = $this->publicBodyRepository->find(Uuid::fromString($publicBodyId));
        if (null === $publicBody) {
            throw new InvalidArgumentException('PublicBody not found.');
        }

        // Channel resolution: if the body is reachable via REG, a valid unit is
        // required so the draft can actually be submitted. Otherwise it is a
        // portal/email draft and any regDestinationId is ignored.
        $regDestination = null;
        if ($this->regDestinationRepository->bodyHasActiveDestinations($publicBody)) {
            $regDestination = $this->resolveRegDestination($publicBody, $regDestinationId);
        }

        $applicableLaw = $this->resolveApplicableLaw($publicBody, $applicableLawId);

        // Build the draft in memory (channel drives the composer); persist only
        // once the AI actually produces a draft.
        $request = new AccessRequest();
        $request->setUser($user);
        $request->setOrganization($user->getOrganization());
        $request->setTitle('');
        $request->setDescription('');
        $request->setPublicBody($publicBody);
        $request->setRegDestination($regDestination);
        $request->setApplicableLaw($applicableLaw);
        $request->setStatus(AccessRequest::STATUS_PENDING);
        $request->setMetadataValue('generated_via', 'mcp/' . $this->tokenContext->getClientId());
        $request->setMetadataValue('draft_batch_id', Uuid::v7()->toRfc4122());

        try {
            $this->requestDraftGenerator->generate($request, $prompt);
        } catch (RequestDraftNotGeneratedException $e) {
            // Nothing persisted: relay the assistant's ask for more context.
            throw new InvalidArgumentException('No se pudo generar el borrador todavía: ' . $e->reply);
        }

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return AccessRequestSummary::fromEntity($request);
    }

    private function resolveRegDestination(PublicBody $publicBody, ?string $regDestinationId): RegDestination
    {
        if (null === $regDestinationId) {
            throw new InvalidArgumentException('This public body is reachable via REG; regDestinationId is required. Use search_reg_destinations to find one.');
        }
        if (!Uuid::isValid($regDestinationId)) {
            throw new InvalidArgumentException('regDestinationId must be a UUID.');
        }

        $regDestination = $this->regDestinationRepository->find(Uuid::fromString($regDestinationId));
        if (null === $regDestination
            || $regDestination->isDisabled()
            || 0 !== $regDestination->getSubmissionTarget()->getId()->compare($publicBody->getId())
        ) {
            throw new InvalidArgumentException('regDestinationId does not belong to publicBodyId or is not active.');
        }

        return $regDestination;
    }

    private function resolveApplicableLaw(PublicBody $publicBody, ?string $applicableLawId): ApplicableLaw
    {
        if (null !== $applicableLawId) {
            if (!Uuid::isValid($applicableLawId)) {
                throw new InvalidArgumentException('applicableLawId must be a UUID.');
            }
            $law = $this->applicableLawRepository->find(Uuid::fromString($applicableLawId));
            if (null === $law) {
                throw new InvalidArgumentException('ApplicableLaw not found.');
            }

            return $law;
        }

        $law = $this->applicableLawResolver->resolveFor($publicBody);
        if (null === $law) {
            throw new InvalidArgumentException('Default state law not configured.');
        }

        return $law;
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
