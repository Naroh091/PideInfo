<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('UniversalDropzone')]
final class UniversalDropzone extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $accessRequestId = null;

    #[LiveProp]
    public array $uploadedFiles = [];

    #[LiveProp]
    public bool $isUploading = false;

    #[LiveProp]
    public ?string $error = null;

    #[LiveProp]
    public ?string $successMessage = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly Security $security,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[LiveAction]
    public function upload(#[LiveArg] UploadedFile $file): void
    {
        $this->error = null;
        $this->successMessage = null;
        $this->isUploading = true;

        try {
            /** @var User $user */
            $user = $this->security->getUser();
            if (!$user) {
                $this->error = 'No estás autenticado';
                return;
            }

            // Find or create access request
            $accessRequest = null;
            if ($this->accessRequestId) {
                $accessRequest = $this->accessRequestRepository->find($this->accessRequestId);
            }

            // Create document entity
            $document = new Document();
            $document->setUploadedBy($user);
            $document->setAccessRequest($accessRequest);
            $document->setOriginalFilename($file->getClientOriginalName());
            $document->setMimeType($file->getMimeType() ?? 'application/octet-stream');
            $document->setFileSize($file->getSize());
            $document->setContentHash(hash_file('sha256', $file->getPathname()));
            $document->setType(DocumentType::Unprocessed);
            $document->setProcessed(false);

            // Generate storage path
            $extension = $file->guessExtension() ?? 'bin';
            $storedFilename = sprintf(
                '%s/%s/%s.%s',
                $user->getId(),
                date('Y/m'),
                bin2hex(random_bytes(16)),
                $extension
            );
            $document->setStoredFilename($storedFilename);

            // Upload file
            $stream = fopen($file->getPathname(), 'r');
            $this->documentsStorage->writeStream($storedFilename, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            // Dispatch message for async AI processing
            $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));

            $this->uploadedFiles[] = [
                'id' => (string) $document->getId(),
                'name' => $document->getOriginalFilename(),
                'size' => $document->getFileSizeFormatted(),
                'status' => 'pending',
            ];

            $this->successMessage = sprintf('"%s" subido correctamente', $document->getOriginalFilename());
        } catch (\Exception $e) {
            $this->error = 'Error al subir el archivo: ' . $e->getMessage();
        } finally {
            $this->isUploading = false;
        }
    }

    public function getRecentDocuments(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        $documents = $this->entityManager->getRepository(Document::class)->findBy(
            ['uploadedBy' => $user, 'processed' => false],
            ['createdAt' => 'DESC'],
            5
        );

        return array_map(fn(Document $doc) => [
            'id' => (string) $doc->getId(),
            'name' => $doc->getOriginalFilename(),
            'size' => $doc->getFileSizeFormatted(),
            'uploadedAt' => $doc->getCreatedAt()->format('d/m/Y H:i'),
            'processed' => $doc->isProcessed(),
        ], $documents);
    }
}
