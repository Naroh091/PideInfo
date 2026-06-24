<?php

namespace App\Controller\Webhook;

use App\Entity\Document;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
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

class InboundEmailController extends AbstractController
{
    private const ALLOWED_ATTACHMENT_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    private const MAX_PAYLOAD_SIZE = 50 * 1024 * 1024; // 50MB

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'INBOUND_EMAIL_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        #[Autowire(service: 'limiter.inbound_email')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    #[Route('/webhook/inbound-email', name: 'app_webhook_inbound_email', methods: ['POST'])]
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
            $this->logger->warning('Inbound email webhook: invalid secret');
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

        $toAddress = $data['to'] ?? '';
        $from = $data['from'] ?? '';
        $subject = $data['subject'] ?? '(sin asunto)';
        $date = $data['date'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $textBody = $data['textBody'] ?? '';
        $htmlBody = $data['htmlBody'] ?? '';
        $attachments = $data['attachments'] ?? [];

        // Look up user by virtual email — return 200 silently if not found
        $user = $this->userRepository->findByVirtualEmail($toAddress);
        if (!$user) {
            $this->logger->info('Inbound email to unknown address', ['to' => $toAddress]);
            return new JsonResponse(['ok' => true]);
        }

        // Skip emails with no body and no attachments
        $bodyText = $textBody ?: strip_tags($htmlBody);
        if (empty(trim($bodyText)) && empty($attachments)) {
            $this->logger->info('Inbound email skipped: empty body and no attachments', ['to' => $toAddress, 'from' => $from]);
            return new JsonResponse(['ok' => true, 'skipped' => true]);
        }

        // Deduplicate: hash from+date+subject+attachment count
        $emailHash = hash('sha256', $from . $date . $subject . count($attachments));
        $existing = $this->entityManager->getRepository(Document::class)->findOneBy([
            'uploadedBy' => $user,
            'sourceType' => Document::SOURCE_EMAIL,
        ]);
        // Simple dedup: check if we recently processed the same email hash
        if ($existing && ($existing->getSourceMetadata()['emailHash'] ?? null) === $emailHash) {
            $this->logger->info('Inbound email skipped: duplicate', ['hash' => $emailHash]);
            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }

        $emailGroupId = Uuid::v7()->toRfc4122();
        $sourceMetadata = [
            'from' => $from,
            'subject' => $subject,
            'date' => $date,
            'emailGroupId' => $emailGroupId,
            'emailHash' => $emailHash,
        ];

        $documentIds = [];

        // Store email body as a text document
        if (!empty(trim($bodyText))) {
            $bodyDocument = $this->createDocumentFromContent(
                user: $user,
                content: $bodyText,
                originalFilename: 'Email: ' . mb_substr($subject, 0, 200),
                mimeType: 'text/plain',
                sourceType: Document::SOURCE_EMAIL,
                sourceMetadata: $sourceMetadata,
            );
            $documentIds[] = $bodyDocument->getId();
        }

        // Store each attachment
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'] ?? 'attachment';
            $contentType = $attachment['contentType'] ?? 'application/octet-stream';
            $base64Content = $attachment['content'] ?? '';

            // Filter by allowed MIME types
            if (!in_array($contentType, self::ALLOWED_ATTACHMENT_MIMES, true)) {
                $this->logger->info('Inbound email: skipping attachment with unsupported type', [
                    'filename' => $filename,
                    'contentType' => $contentType,
                ]);
                continue;
            }

            $content = base64_decode($base64Content, true);
            if ($content === false) {
                $this->logger->warning('Inbound email: failed to decode attachment', ['filename' => $filename]);
                continue;
            }

            // Tiny images (< 10 KB) are virtually always email-signature logos
            // or social-media icons — drop them before the AI pipeline picks
            // them up and creates orphan documents.
            if (\App\Service\Document\DocumentIngestionFilter::isTinyImage($contentType, strlen($content))) {
                $this->logger->info('Inbound email: skipping tiny image attachment', [
                    'filename' => $filename,
                    'contentType' => $contentType,
                    'size' => strlen($content),
                ]);
                continue;
            }

            $attachmentDocument = $this->createDocumentFromContent(
                user: $user,
                content: $content,
                originalFilename: $filename,
                mimeType: $contentType,
                sourceType: Document::SOURCE_EMAIL,
                sourceMetadata: $sourceMetadata,
            );
            $documentIds[] = $attachmentDocument->getId();
        }

        if (empty($documentIds)) {
            return new JsonResponse(['ok' => true, 'documents' => 0]);
        }

        $this->entityManager->flush();

        // Dispatch batch processing if multiple documents, single otherwise
        if (count($documentIds) > 1) {
            $this->messageBus->dispatch(new ProcessDocumentBatchMessage($documentIds));
        } else {
            $this->messageBus->dispatch(new ProcessDocumentMessage($documentIds[0]));
        }

        $this->logger->info('Inbound email processed', [
            'to' => $toAddress,
            'from' => $from,
            'subject' => $subject,
            'documents' => count($documentIds),
        ]);

        return new JsonResponse([
            'ok' => true,
            'documents' => count($documentIds),
        ]);
    }

    private function createDocumentFromContent(
        \App\Entity\User $user,
        string $content,
        string $originalFilename,
        string $mimeType,
        string $sourceType,
        array $sourceMetadata,
    ): Document {
        $extension = $this->guessExtension($mimeType, $originalFilename);
        $storedFilename = sprintf(
            '%s/%s/%s.%s',
            $user->getId(),
            date('Y/m'),
            bin2hex(random_bytes(16)),
            $extension
        );

        $this->documentsStorage->write($storedFilename, $content);

        $document = new Document();
        $document->setUploadedBy($user);
        $document->setOriginalFilename($originalFilename);
        $document->setStoredFilename($storedFilename);
        $document->setMimeType($mimeType);
        $document->setFileSize(strlen($content));
        $document->setSourceType($sourceType);
        $document->setSourceMetadata($sourceMetadata);
        $document->setContentHash(hash('sha256', $content));

        $this->entityManager->persist($document);

        return $document;
    }

    private function guessExtension(string $mimeType, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext) {
            return strtolower($ext);
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => 'bin',
        };
    }
}
