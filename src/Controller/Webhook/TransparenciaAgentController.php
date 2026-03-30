<?php

namespace App\Controller\Webhook;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class TransparenciaAgentController extends AbstractController
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_PAYLOAD_SIZE = 50 * 1024 * 1024; // 50MB

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly UserRepository $userRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TRANSPARENCIA_AGENT_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        #[Autowire(service: 'limiter.transparencia_agent')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    #[Route('/webhook/transparencia-sync', name: 'app_webhook_transparencia_sync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Rate limit by IP
        $limiter = $this->rateLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Authenticate
        $secret = $request->headers->get('X-Webhook-Secret');
        if (!$secret || !hash_equals($this->webhookSecret, $secret)) {
            $this->logger->warning('Transparencia agent webhook: invalid secret');
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Check payload size
        if ($request->headers->get('Content-Length', 0) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $data['userId'] ?? '';
        $source = $data['source'] ?? 'transparencia_age';
        $expedienteRef = $data['expedienteRef'] ?? '';
        $documents = $data['documents'] ?? [];
        $metadata = $data['metadata'] ?? [];

        // Look up user by UUID
        if (!$userId || !Uuid::isValid($userId)) {
            return new JsonResponse(['error' => 'Invalid or missing userId'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            $this->logger->warning('Transparencia agent: unknown user', ['userId' => $userId]);
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if (empty($documents)) {
            return new JsonResponse(['ok' => true, 'documents' => 0, 'created' => 0, 'skipped' => []]);
        }

        // Look up an existing AccessRequest matching this expediente reference
        $accessRequest = $expedienteRef
            ? $this->accessRequestRepository->findByExternalId($expedienteRef, $user)
            : null;

        $documentIds = [];
        $skipped = [];

        foreach ($documents as $doc) {
            $filename = $doc['filename'] ?? 'document.pdf';
            $contentType = $doc['contentType'] ?? 'application/pdf';
            $base64Content = $doc['content'] ?? '';
            $contentHash = $doc['contentHash'] ?? null;

            // Filter by allowed MIME types
            if (!in_array($contentType, self::ALLOWED_MIMES, true)) {
                $this->logger->info('Transparencia agent: skipping unsupported type', [
                    'filename' => $filename,
                    'contentType' => $contentType,
                ]);
                $skipped[] = ['filename' => $filename, 'reason' => 'unsupported_type'];
                continue;
            }

            $content = base64_decode($base64Content, true);
            if ($content === false) {
                $this->logger->warning('Transparencia agent: failed to decode content', ['filename' => $filename]);
                $skipped[] = ['filename' => $filename, 'reason' => 'decode_error'];
                continue;
            }

            // Calculate content hash if not provided
            if (!$contentHash) {
                $contentHash = hash('sha256', $content);
            }

            // Check for duplicate by content hash
            $existing = $this->entityManager->getRepository(Document::class)->findOneBy([
                'uploadedBy' => $user,
                'contentHash' => $contentHash,
            ]);

            if ($existing) {
                // If the document exists but isn't linked to this access request yet, link it now
                if ($accessRequest !== null && $existing->getAccessRequest() === null) {
                    $accessRequest->addDocument($existing);
                }
                $this->logger->info('Transparencia agent: duplicate document skipped', [
                    'filename' => $filename,
                    'contentHash' => $contentHash,
                ]);
                $skipped[] = ['filename' => $filename, 'reason' => 'duplicate'];
                continue;
            }

            $sourceMetadata = [
                'source' => $source,
                'expedienteRef' => $expedienteRef,
                ...$metadata,
            ];

            $document = $this->createDocument(
                user: $user,
                content: $content,
                originalFilename: $filename,
                mimeType: $contentType,
                contentHash: $contentHash,
                sourceMetadata: $sourceMetadata,
                accessRequest: $accessRequest,
            );
            $documentIds[] = $document->getId();
        }

        if (empty($documentIds)) {
            return new JsonResponse([
                'ok' => true,
                'documents' => count($documents),
                'created' => 0,
                'skipped' => $skipped,
            ]);
        }

        $this->entityManager->flush();

        // Dispatch processing.
        // - No existing AccessRequest: send as a batch so the AI analyzes all
        //   documents together and creates the request from the first (SOLICITUD).
        // - Existing AccessRequest: dispatch individually so each document is
        //   processed against the known request without re-running batch creation.
        if ($accessRequest === null && count($documentIds) > 1) {
            $this->messageBus->dispatch(new ProcessDocumentBatchMessage($documentIds));
        } else {
            foreach ($documentIds as $documentId) {
                $this->messageBus->dispatch(new ProcessDocumentMessage($documentId));
            }
        }

        $this->logger->info('Transparencia agent: documents synced', [
            'userId' => $userId,
            'source' => $source,
            'expedienteRef' => $expedienteRef,
            'created' => count($documentIds),
            'skipped' => count($skipped),
        ]);

        return new JsonResponse([
            'ok' => true,
            'documents' => count($documents),
            'created' => count($documentIds),
            'skipped' => $skipped,
        ]);
    }

    private function createDocument(
        \App\Entity\User $user,
        string $content,
        string $originalFilename,
        string $mimeType,
        string $contentHash,
        array $sourceMetadata,
        ?AccessRequest $accessRequest = null,
    ): Document {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => 'bin',
        };

        $storedFilename = sprintf(
            '%s/%s/%s.%s',
            $user->getId(),
            date('Y/m'),
            bin2hex(random_bytes(16)),
            strtolower($extension)
        );

        $this->documentsStorage->write($storedFilename, $content);

        $document = new Document();
        $document->setUploadedBy($user);
        $document->setOriginalFilename($originalFilename);
        $document->setStoredFilename($storedFilename);
        $document->setMimeType($mimeType);
        $document->setFileSize(strlen($content));
        $document->setSourceType(Document::SOURCE_PORTAL);
        $document->setSourceMetadata($sourceMetadata);
        $document->setContentHash($contentHash);

        if ($accessRequest !== null) {
            $accessRequest->addDocument($document);
        }

        $this->entityManager->persist($document);

        return $document;
    }
}
