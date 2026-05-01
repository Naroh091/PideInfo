<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Agent-only document download endpoint.
 *
 * The web {@see DocumentController::download} lives under the session
 * firewall — the agent uses JWT and needs a parallel route under
 * `/api/agent/`. Same auth rules: only the document's uploader can fetch it.
 */
#[Route('/api/agent/documents')]
#[IsGranted('ROLE_USER')]
class AgentDocumentApiController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly FilesystemOperator $documentsStorage,
    ) {
    }

    #[Route('/{id}/download', name: 'api_agent_document_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_id'], Response::HTTP_BAD_REQUEST);
        }

        $document = $this->documentRepository->find($uuid);
        if ($document === null
            || $document->getUploadedBy()?->getId()?->toRfc4122() !== $user->getId()->toRfc4122()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $stored = $document->getStoredFilename();
        if (!$stored || !$this->documentsStorage->fileExists($stored)) {
            return new JsonResponse(['error' => 'file_missing'], Response::HTTP_GONE);
        }

        $documentsStorage = $this->documentsStorage;
        return new StreamedResponse(
            function () use ($documentsStorage, $stored): void {
                $stream = $documentsStorage->readStream($stored);
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $document->getMimeType() ?: 'application/octet-stream',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $document->getOriginalFilename() ?: $id . '.bin'),
                'Content-Length' => (string) $document->getFileSize(),
            ],
        );
    }
}
