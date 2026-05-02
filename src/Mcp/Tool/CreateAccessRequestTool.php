<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\AccessRequestSummary;
use App\Repository\ApplicableLawRepository;
use App\Repository\PublicBodyRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Submission\ApplicableLawResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Create a new access request (FOIA filing) on behalf of the authenticated user.
 */
#[McpTool(
    name: 'create_access_request',
    description: 'Crea una nueva solicitud de acceso a información pública en nombre del usuario. Calcula el plazo según la ley aplicable.',
)]
final class CreateAccessRequestTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly ApplicableLawResolver $applicableLawResolver,
    ) {
    }

    /**
     * @param string      $title         Short title visible in the panel.
     * @param string      $description   Body of the request (the actual question to the administration).
     * @param string      $publicBodyId  UUID of the target PublicBody.
     * @param string|null $applicableLawId   UUID of the ApplicableLaw; when null the state law is used.
     * @param string|null $sentAt        ISO 8601 date when the request was filed; defaults to today.
     * @param string|null $externalId    Optional reference number issued by the public body.
     */
    public function __invoke(
        string $title,
        string $description,
        string $publicBodyId,
        ?string $applicableLawId = null,
        ?string $sentAt = null,
        ?string $externalId = null,
    ): AccessRequestSummary {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        $title = trim($title);
        $description = trim($description);
        if ('' === $title || '' === $description) {
            throw new InvalidArgumentException('title and description are required.');
        }

        if (!Uuid::isValid($publicBodyId)) {
            throw new InvalidArgumentException('publicBodyId must be a UUID.');
        }
        $publicBody = $this->publicBodyRepository->find(Uuid::fromString($publicBodyId));
        if (null === $publicBody) {
            throw new InvalidArgumentException('PublicBody not found.');
        }

        if (null !== $applicableLawId) {
            if (!Uuid::isValid($applicableLawId)) {
                throw new InvalidArgumentException('applicableLawId must be a UUID.');
            }
            $applicableLaw = $this->applicableLawRepository->find(Uuid::fromString($applicableLawId));
            if (null === $applicableLaw) {
                throw new InvalidArgumentException('ApplicableLaw not found.');
            }
        } else {
            // Auto-resolve from the public body: CCAA-derived law if any,
            // state law as fallback. Same rule the "realizar" UI applies.
            $applicableLaw = $this->applicableLawResolver->resolveFor($publicBody);
            if (null === $applicableLaw) {
                throw new InvalidArgumentException('Default state law not configured.');
            }
        }

        try {
            $sent = null !== $sentAt
                ? new \DateTimeImmutable($sentAt)
                : new \DateTimeImmutable('today');
        } catch (\Exception $e) {
            throw new InvalidArgumentException('sentAt must be a valid ISO 8601 date.');
        }

        $request = $this->accessRequestManager->create(
            user: $user,
            title: $title,
            description: $description,
            publicBody: $publicBody,
            applicableLaw: $applicableLaw,
            sentAt: $sent,
            externalId: $externalId,
        );

        return AccessRequestSummary::fromEntity($request);
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
