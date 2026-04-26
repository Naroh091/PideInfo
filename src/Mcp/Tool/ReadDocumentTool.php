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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Read a user's document either as plain text (extracted/cached) or as the raw
 * blob in base64. Operational alternative to the `pideinfo://document/{uuid}`
 * resource template while the SDK PHP does not route resource templates.
 */
#[McpTool(
    name: 'read_document',
    description: 'Lee el contenido de un documento propio. Por defecto devuelve texto extraído (PDF/text); con mode=blob devuelve el binario en base64.',
)]
final class ReadDocumentTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentContentReader $reader,
    ) {
    }

    /**
     * @param string $documentId UUID of the document to read.
     * @param string $mode       'text' (default) returns extracted text; 'blob' returns base64 of the raw bytes.
     */
    public function __invoke(string $documentId, string $mode = 'text'): DocumentContent
    {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($documentId)) {
            throw new InvalidArgumentException('Invalid document id.');
        }
        if (!in_array($mode, ['text', 'blob'], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'text' or 'blob'.");
        }

        $document = $this->documentRepository->find(Uuid::fromString($documentId));
        if (null === $document) {
            throw new InvalidArgumentException('Document not found.');
        }
        if ($document->getUploadedBy()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Document does not belong to the authenticated user.');
        }

        return $this->reader->read($document, $mode);
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
