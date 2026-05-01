<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\DocumentContent;
use App\Mcp\Service\DocumentContentReader;
use App\Repository\DocumentRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * MCP-side mirror of {@see \App\Controller\Api\AgentDocumentApiController::download}.
 *
 * Returns the raw bytes of one of the user's documents as base64. Behaviourally
 * equivalent to `read_document` with `mode=blob`, but exposed under a clearer
 * name so MCP clients building "download attachment" flows discover it
 * without having to know about the optional mode flag.
 */
#[McpTool(
    name: 'download_user_document',
    description: 'Descarga un documento propio del usuario y devuelve su contenido binario en base64. Equivalente a read_document con mode=blob.',
)]
final class DownloadUserDocumentTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentContentReader $reader,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $documentId UUID of the document to download.
     */
    public function __invoke(string $documentId): DocumentContent
    {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($documentId)) {
            throw new InvalidArgumentException('Invalid document id.');
        }

        $document = $this->documentRepository->find(Uuid::fromString($documentId));
        if (null === $document) {
            throw new InvalidArgumentException('Document not found.');
        }
        if ($document->getUploadedBy()?->getId()?->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Document does not belong to the authenticated user.');
        }

        $this->logger->info('mcp:download_user_document', [
            'user' => $user->getId()->toRfc4122(),
            'document' => $documentId,
            'channel' => sprintf('[mcp/%s]', $this->tokenContext->getClientId() ?? '?'),
        ]);

        return $this->reader->read($document, 'blob');
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
