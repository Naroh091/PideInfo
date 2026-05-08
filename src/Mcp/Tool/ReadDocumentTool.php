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
 * Read a user's document as plain text (extracted/cached) or as a download link
 * (pre-signed S3 URL). Replaces the old 'blob' mode which returned raw base64
 * and was prone to truncation on large files.
 */
#[McpTool(
    name: 'read_document',
    description: 'Lee el contenido de un documento propio. mode=text devuelve texto extraído (PDF/text); mode=url devuelve una pre-signed URL de descarga (expira en 15 minutos).',
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
     * @param string $mode       'text' (default) returns extracted text; 'url' returns a pre-signed download link.
     */
    public function __invoke(string $documentId, string $mode = 'text'): DocumentContent
    {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($documentId)) {
            throw new InvalidArgumentException('Invalid document id.');
        }
        if (!in_array($mode, ['text', 'url'], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'text' or 'url'.");
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
