<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\OAuth2\OAuthTokenContext;
use League\Flysystem\FilesystemOperator;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\BlobResourceContents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Streams documents owned by the authenticated user as MCP resources.
 *
 * Resource Templates are not yet fully supported by the SDK; once the SDK can
 * route `pideinfo://document/{uuid}` URIs here, this provider will respond.
 * In the meantime, ListUserDocumentsTool surfaces concrete URIs for the client
 * to request — when that lands, this provider answers them.
 */
#[McpResourceTemplate(
    uriTemplate: 'pideinfo://document/{uuid}',
    name: 'pideinfo_document',
    description: 'Documento adjunto a una solicitud (PDF, Word, imagen, etc.). Solo accesible al propietario.',
    mimeType: 'application/octet-stream',
)]
final class DocumentResourceProvider
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly DocumentRepository $documentRepository,
        private readonly FilesystemOperator $documentsStorage,
    ) {
    }

    /**
     * @param string $uuid UUID of the document to read.
     */
    public function __invoke(string $uuid): BlobResourceContents
    {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($uuid)) {
            throw new InvalidArgumentException('Invalid document uuid.');
        }

        $document = $this->documentRepository->find(Uuid::fromString($uuid));
        if (null === $document) {
            throw new InvalidArgumentException('Document not found.');
        }

        if ($document->getUploadedBy()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Document does not belong to the authenticated user.');
        }

        $stored = $document->getStoredFilename();
        if (!$this->documentsStorage->fileExists($stored)) {
            throw new InvalidArgumentException('Document blob is missing from storage.');
        }

        $stream = $this->documentsStorage->readStream($stored);
        $bytes = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return new BlobResourceContents(
            uri: 'pideinfo://document/'.$document->getId()->toRfc4122(),
            mimeType: $document->getMimeType(),
            blob: base64_encode((string) $bytes),
        );
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
