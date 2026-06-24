<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Mcp\Dto\DocumentSummary;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Uploads a document (PDF/image) supplied inline as base64 and attaches it to a
 * request's expediente, then queues it for the normal processing pipeline.
 * Inline payloads are capped at 1 MiB; larger files should be uploaded via the
 * web app.
 */
#[McpTool(
    name: 'upload_document',
    description: 'Aporta un documento (PDF o imagen) al expediente de una solicitud, enviándolo inline en base64 (máx. 1 MiB). Se procesa como cualquier documento subido. Para archivos mayores, usa la app web.',
)]
final class UploadDocumentTool
{
    /** Mirror DocumentResourceProvider::INLINE_BLOB_LIMIT (1 MiB). */
    private const MAX_INLINE_BYTES = 1_048_576;

    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly FilesystemOperator $documentsStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $requestId     UUID de la solicitud a la que adjuntar el documento.
     * @param string      $filename      Nombre original del archivo (p. ej. "resolucion.pdf").
     * @param string      $contentBase64 Contenido del archivo codificado en base64 (máx. 1 MiB ya decodificado).
     * @param string|null $documentType  Pista opcional de tipo (request, response, notification, resolution…); si no, se clasifica automáticamente.
     */
    public function __invoke(
        string $requestId,
        string $filename,
        string $contentBase64,
        ?string $documentType = null,
    ): DocumentSummary {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $bytes = base64_decode(trim($contentBase64), true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('contentBase64 is not valid base64.');
        }
        if (strlen($bytes) > self::MAX_INLINE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'File is too large for inline upload (%d bytes, max %d). Use the web app for larger files.',
                strlen($bytes),
                self::MAX_INLINE_BYTES,
            ));
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported file type "%s". Allowed: PDF, JPEG, PNG, GIF.', $mime));
        }

        $contentHash = hash('sha256', $bytes);
        $existing = $this->documentRepository->findOneBy(['uploadedBy' => $user, 'contentHash' => $contentHash]);
        if ($existing !== null) {
            // Idempotent: the same bytes are already in the user's documents.
            return DocumentSummary::fromEntity($existing);
        }

        $hint = $this->resolveTypeHint($documentType);

        $document = new Document();
        $document->setUploadedBy($user);
        $document->setAccessRequest($request);
        $document->setOriginalFilename(mb_substr(trim($filename) ?: 'documento', 0, 255));
        $document->setMimeType($mime);
        $document->setFileSize(strlen($bytes));
        $document->setContentHash($contentHash);
        $document->setType($hint ?? DocumentType::Unprocessed);
        $document->setProcessed(false);
        $document->setAiMetadata(array_filter([
            'origin' => 'mcp/' . $this->tokenContext->getClientId(),
            'documentType' => $documentType,
        ], static fn ($v) => $v !== null));

        $storedFilename = sprintf(
            '%s/%s/%s.%s',
            $user->getId(),
            date('Y/m'),
            bin2hex(random_bytes(16)),
            $this->extensionFor($mime),
        );
        $document->setStoredFilename($storedFilename);
        $this->documentsStorage->write($storedFilename, $bytes);

        $this->em->persist($document);
        $this->em->flush();

        // Same pipeline as the web upload: extract text + classify.
        $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));

        return DocumentSummary::fromEntity($document);
    }

    private function resolveTypeHint(?string $documentType): ?DocumentType
    {
        if ($documentType === null || $documentType === '') {
            return null;
        }

        return DocumentType::tryFrom($documentType);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'bin',
        };
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
