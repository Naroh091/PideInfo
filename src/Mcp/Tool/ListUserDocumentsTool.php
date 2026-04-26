<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\DocumentSummary;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * List documents owned by the user, optionally filtered to one access request.
 * Each entry exposes a `pideinfo://document/{uuid}` URI usable with resources/read.
 */
#[McpTool(
    name: 'list_user_documents',
    description: 'Lista los documentos del usuario, con URIs MCP para leer su contenido. Filtrable por solicitud.',
)]
final class ListUserDocumentsTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly DocumentRepository $documentRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    /**
     * @param string|null $requestId Optional access-request UUID; when set, only documents on that request are returned.
     * @param int         $limit     Maximum entries (1-200, default 50).
     *
     * @return list<DocumentSummary>
     */
    public function __invoke(?string $requestId = null, int $limit = 50): array
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        $limit = max(1, min(200, $limit));

        if (null !== $requestId) {
            if (!Uuid::isValid($requestId)) {
                throw new InvalidArgumentException('Invalid request id.');
            }
            $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
            if (null === $request || $request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
                throw new AccessDeniedException('Request not found or not yours.');
            }

            $documents = $request->getDocuments()->toArray();
            $documents = array_slice($documents, 0, $limit);

            return array_map(static fn ($d) => DocumentSummary::fromEntity($d), $documents);
        }

        $documents = $this->documentRepository->findBy(
            ['uploadedBy' => $user],
            ['createdAt' => 'DESC'],
            $limit,
        );

        return array_map(static fn ($d) => DocumentSummary::fromEntity($d), $documents);
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
