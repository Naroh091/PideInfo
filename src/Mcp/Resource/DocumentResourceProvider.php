<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Aws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Streams documents owned by the authenticated user as MCP resources.
 *
 * For files under {@see self::INLINE_BLOB_LIMIT}, returns the bytes directly
 * as a {@see BlobResourceContents}. For larger files, returns a
 * {@see TextResourceContents} with mime `text/uri-list` carrying a pre-signed
 * S3 URL the client must fetch — encoding a URL as base64 inside a blob is a
 * lie about the content shape and clients can't tell it apart from real bytes.
 *
 * Resource Templates are not yet fully supported by the SDK; once the SDK can
 * route `pideinfo://document/{uuid}` URIs here, this provider will respond.
 * In the meantime, ListUserDocumentsTool surfaces concrete URIs for the client
 * to request — when that lands, this provider answers them.
 */
#[McpResourceTemplate(
    uriTemplate: 'pideinfo://document/{uuid}',
    name: 'pideinfo_document',
    description: 'Documento adjunto a una solicitud (PDF, Word, imagen, etc.). Solo accesible al propietario. Archivos pequeños se devuelven inline; los grandes vienen como text/uri-list con una pre-signed URL de descarga (15 min).',
    mimeType: 'application/octet-stream',
)]
final class DocumentResourceProvider
{
    /**
     * Files at or below this size are inlined as base64 blobs. Above this we
     * return a pre-signed URL to avoid blowing the MCP channel and to dodge
     * the SDK's tendency to truncate large payloads.
     */
    private const INLINE_BLOB_LIMIT = 1_048_576;

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly DocumentRepository $documentRepository,
        private readonly FilesystemOperator $documentsStorage,
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {
    }

    /**
     * @param string $uuid UUID of the document to read.
     */
    public function __invoke(string $uuid): ResourceContents
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
        $resourceUri = 'pideinfo://document/'.$document->getId()->toRfc4122();

        if ($document->getFileSize() <= self::INLINE_BLOB_LIMIT) {
            if (!$this->documentsStorage->fileExists($stored)) {
                throw new InvalidArgumentException('Document blob is missing from storage.');
            }

            $stream = $this->documentsStorage->readStream($stored);
            $bytes = stream_get_contents($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return new BlobResourceContents(
                uri: $resourceUri,
                mimeType: $document->getMimeType(),
                blob: base64_encode((string) $bytes),
            );
        }

        // Large file: hand back a pre-signed URL as text/uri-list (RFC 2483)
        // so the client unambiguously knows it has to fetch the body itself.
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->s3Bucket,
            'Key' => $stored,
        ]);
        $presignedUrl = (string) $this->s3Client
            ->createPresignedRequest($command, '+15 minutes')
            ->getUri();

        return new TextResourceContents(
            uri: $resourceUri,
            mimeType: 'text/uri-list',
            text: $presignedUrl."\n",
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
