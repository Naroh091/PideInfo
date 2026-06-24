<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\User;
use App\Mcp\Dto\AccessRequestSummary;
use App\Repository\ApplicableLawRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\ChannelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a PENDING access-request draft to converse against with
 * `draft_request_message`. This is distinct from `create_access_request`, which
 * registers an already-SENT request — a draft must be PENDING so the chat flow
 * can edit it before submission.
 */
#[McpTool(
    name: 'start_request_draft',
    description: 'Crea un borrador (estado pendiente) de solicitud de acceso para un destinatario, sobre el que luego se conversa con draft_request_message para redactarlo. Para canal REG hace falta regDestinationId (usa list_reg_destinations). Distinto de create_access_request, que registra una solicitud YA enviada.',
)]
final class StartRequestDraftTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly ChannelResolver $channelResolver,
        private readonly DeadlineCalculator $deadlineCalculator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $publicBodyId     UUID del organismo destinatario.
     * @param string|null $regDestinationId UUID de la unidad DIR3 (obligatorio si el canal del organismo es REG).
     * @param string|null $applicableLawId  UUID de la ley aplicable; si es null se resuelve automáticamente.
     * @param string|null $title            Título inicial opcional del borrador.
     */
    public function __invoke(
        string $publicBodyId,
        ?string $regDestinationId = null,
        ?string $applicableLawId = null,
        ?string $title = null,
    ): AccessRequestSummary {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!Uuid::isValid($publicBodyId)) {
            throw new InvalidArgumentException('publicBodyId must be a UUID.');
        }
        $publicBody = $this->publicBodyRepository->find(Uuid::fromString($publicBodyId));
        if (null === $publicBody) {
            throw new InvalidArgumentException('PublicBody not found.');
        }

        // REG channel needs a concrete DIR3 destination.
        $regDestination = null;
        $channel = $this->channelResolver->resolveTaskType($publicBody);
        if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
            if ($regDestinationId === null) {
                throw new InvalidArgumentException('This body uses the REG channel; regDestinationId is required (see list_reg_destinations).');
            }
            if (!Uuid::isValid($regDestinationId)) {
                throw new InvalidArgumentException('regDestinationId must be a UUID.');
            }
            $regDestination = $this->regDestinationRepository->find(Uuid::fromString($regDestinationId));
            if ($regDestination === null
                || $regDestination->getSubmissionTarget()->getId()->compare($publicBody->getId()) !== 0
                || $regDestination->isDisabled()
            ) {
                throw new InvalidArgumentException('Unknown or inactive REG destination for this body.');
            }
        }

        if (null !== $applicableLawId) {
            if (!Uuid::isValid($applicableLawId)) {
                throw new InvalidArgumentException('applicableLawId must be a UUID.');
            }
            $law = $this->applicableLawRepository->find(Uuid::fromString($applicableLawId));
            if (null === $law) {
                throw new InvalidArgumentException('ApplicableLaw not found.');
            }
        } else {
            $law = $this->applicableLawResolver->resolveFor($publicBody);
            if (null === $law) {
                throw new InvalidArgumentException('No applicable law could be resolved for this body.');
            }
        }

        $clientId = $this->tokenContext->getClientId();
        $sentAt = new \DateTimeImmutable('today');
        $deadline = $this->deadlineCalculator->calculate($sentAt, $law);

        $draft = new AccessRequest();
        $draft->setUser($user);
        $draft->setOrganization($user->getOrganization());
        $draft->setTitle(mb_substr(trim((string) $title), 0, 255));
        $draft->setDescription('');
        $draft->setPublicBody($publicBody);
        $draft->setRegDestination($regDestination);
        $draft->setApplicableLaw($law);
        $draft->setSentAt($sentAt);
        $draft->setDeadlineAt($deadline);
        $draft->setOriginalDeadlineAt($deadline);
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        $draft->setMetadataValue('draft_batch_id', Uuid::v7()->toRfc4122());
        // Record the originating channel (no StatusHistory entry for a brand-new
        // pending draft, matching the web flow).
        $draft->setMetadataValue('created_via', 'mcp/' . $clientId);

        $this->em->persist($draft);
        $this->em->flush();

        return AccessRequestSummary::fromEntity($draft);
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
