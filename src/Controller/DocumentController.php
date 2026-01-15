<?php

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/documentos')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/subir', name: 'app_document_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No se ha recibido ningún archivo'], Response::HTTP_BAD_REQUEST);
        }

        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            return new JsonResponse(['error' => 'El archivo es demasiado grande (máximo 10MB)'], Response::HTTP_BAD_REQUEST);
        }

        // Check file type
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return new JsonResponse(['error' => 'Tipo de archivo no permitido'], Response::HTTP_BAD_REQUEST);
        }

        // Get optional access request ID
        $accessRequestId = $request->request->get('accessRequestId');
        $accessRequest = null;
        if ($accessRequestId) {
            $accessRequest = $this->accessRequestRepository->find($accessRequestId);
            if ($accessRequest && $accessRequest->getUser() !== $user) {
                return new JsonResponse(['error' => 'No tienes acceso a esta solicitud'], Response::HTTP_FORBIDDEN);
            }
        }

        try {
            // Create document entity
            $document = new Document();
            $document->setUploadedBy($user);
            $document->setAccessRequest($accessRequest);
            $document->setOriginalFilename($file->getClientOriginalName());
            $document->setMimeType($file->getMimeType() ?? 'application/octet-stream');
            $document->setFileSize($file->getSize());
            $document->setType('unprocessed');
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

            // Only dispatch immediately if not deferred
            $deferProcessing = $request->request->get('deferProcessing') === '1';
            if (!$deferProcessing) {
                $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));
            }

            return new JsonResponse([
                'success' => true,
                'document' => [
                    'id' => (string) $document->getId(),
                    'name' => $document->getOriginalFilename(),
                    'size' => $document->getFileSizeFormatted(),
                    'type' => $document->getType(),
                    'processed' => $document->isProcessed(),
                ],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error al subir el archivo: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/procesar', name: 'app_document_process', methods: ['POST'])]
    public function process(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $documentIds = $data['documentIds'] ?? [];
        $asRelated = $data['asRelated'] ?? false;

        if (empty($documentIds)) {
            return new JsonResponse(['error' => 'No se especificaron documentos'], Response::HTTP_BAD_REQUEST);
        }

        // Verify all documents belong to the user
        $documents = [];
        foreach ($documentIds as $id) {
            $document = $this->entityManager->getRepository(Document::class)->find($id);
            if (!$document) {
                return new JsonResponse(['error' => "Documento $id no encontrado"], Response::HTTP_NOT_FOUND);
            }
            if ($document->getUploadedBy() !== $user) {
                return new JsonResponse(['error' => 'No tienes acceso a uno de los documentos'], Response::HTTP_FORBIDDEN);
            }
            $documents[] = $document;
        }

        // Dispatch appropriate message
        if ($asRelated && count($documents) > 1) {
            // Process as a batch
            $uuids = array_map(fn($d) => $d->getId(), $documents);
            $this->messageBus->dispatch(new ProcessDocumentBatchMessage($uuids));
        } else {
            // Process individually
            foreach ($documents as $document) {
                $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));
            }
        }

        return new JsonResponse([
            'success' => true,
            'message' => $asRelated
                ? 'Documentos enviados para análisis conjunto'
                : 'Documentos enviados para análisis individual',
        ]);
    }

    #[Route('/{id}/descargar', name: 'app_document_download', methods: ['GET'])]
    public function download(Document $document): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check access
        if ($document->getUploadedBy() !== $user) {
            throw $this->createAccessDeniedException('No tienes acceso a este documento');
        }

        if (!$this->documentsStorage->fileExists($document->getStoredFilename())) {
            throw $this->createNotFoundException('Documento no encontrado');
        }

        $storedFilename = $document->getStoredFilename();
        $documentsStorage = $this->documentsStorage;

        return new StreamedResponse(
            function () use ($documentsStorage, $storedFilename): void {
                $stream = $documentsStorage->readStream($storedFilename);
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $document->getMimeType(),
                'Content-Disposition' => sprintf('attachment; filename="%s"', $document->getOriginalFilename()),
                'Content-Length' => $document->getFileSize(),
            ]
        );
    }

    #[Route('/{id}/eliminar', name: 'app_document_delete', methods: ['POST', 'DELETE'])]
    public function delete(Request $request, Document $document): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check access
        if ($document->getUploadedBy() !== $user) {
            throw $this->createAccessDeniedException('No tienes acceso a este documento');
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('delete-document-' . $document->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido');
        }

        try {
            // Delete file from storage
            if ($this->documentsStorage->fileExists($document->getStoredFilename())) {
                $this->documentsStorage->delete($document->getStoredFilename());
            }

            // Remove from database
            $this->entityManager->remove($document);
            $this->entityManager->flush();

            $this->addFlash('success', 'Documento eliminado correctamente');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Error al eliminar el documento');
        }

        // Redirect back to the access request if one was associated
        if ($document->getAccessRequest()) {
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $document->getAccessRequest()->getId()]);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/keyword-matched', name: 'app_document_keyword_matched', methods: ['GET'])]
    public function keywordMatched(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $documents = $this->documentRepository->findKeywordMatchedByUser($user);

        $result = [];
        foreach ($documents as $document) {
            $accessRequest = $document->getAccessRequest();
            $result[] = [
                'id' => (string) $document->getId(),
                'name' => $document->getOriginalFilename(),
                'type' => $document->getType(),
                'typeLabel' => $document->getTypeLabel(),
                'accessRequest' => $accessRequest ? [
                    'id' => (string) $accessRequest->getId(),
                    'title' => $accessRequest->getTitle(),
                ] : null,
            ];
        }

        return new JsonResponse(['documents' => $result]);
    }

    #[Route('/{id}/confirm-match', name: 'app_document_confirm_match', methods: ['POST'])]
    public function confirmMatch(Document $document): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($document->getUploadedBy() !== $user) {
            return new JsonResponse(['error' => 'No tienes acceso a este documento'], Response::HTTP_FORBIDDEN);
        }

        // User confirms the keyword match - change to reference match (confirmed)
        $document->setMatchMethod(Document::MATCH_REFERENCE);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Asociacion confirmada']);
    }

    #[Route('/{id}/reject-match', name: 'app_document_reject_match', methods: ['POST'])]
    public function rejectMatch(Document $document): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($document->getUploadedBy() !== $user) {
            return new JsonResponse(['error' => 'No tienes acceso a este documento'], Response::HTTP_FORBIDDEN);
        }

        // User rejects the keyword match - unlink from access request
        $document->setAccessRequest(null);
        $document->setMatchMethod(null);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Asociacion rechazada. El documento queda sin asignar.']);
    }

    #[Route('/status', name: 'app_document_status', methods: ['POST'])]
    public function checkStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $documentIds = $data['documentIds'] ?? [];

        if (empty($documentIds)) {
            return new JsonResponse(['documents' => []]);
        }

        $result = [];
        $hasProcessed = false;
        $hasKeywordMatched = false;

        foreach ($documentIds as $id) {
            $document = $this->documentRepository->find($id);
            if (!$document || $document->getUploadedBy() !== $user) {
                continue;
            }

            $accessRequest = $document->getAccessRequest();
            $isProcessed = $document->isProcessed();
            $isKeywordMatched = $document->getMatchMethod() === Document::MATCH_KEYWORDS;

            if ($isProcessed) {
                $hasProcessed = true;
            }
            if ($isKeywordMatched) {
                $hasKeywordMatched = true;
            }

            $result[] = [
                'id' => (string) $document->getId(),
                'name' => $document->getOriginalFilename(),
                'processed' => $isProcessed,
                'type' => $document->getType(),
                'typeLabel' => $document->getTypeLabel(),
                'matchMethod' => $document->getMatchMethod(),
                'error' => $document->getProcessingError(),
                'accessRequest' => $accessRequest ? [
                    'id' => (string) $accessRequest->getId(),
                    'title' => $accessRequest->getTitle(),
                    'status' => $accessRequest->getStatus(),
                    'statusLabel' => $accessRequest->getStatusLabel(),
                    'url' => $this->generateUrl('app_solicitudes_show', ['id' => $accessRequest->getId()]),
                    'isNew' => $document->getMatchMethod() === Document::MATCH_CREATED,
                ] : null,
            ];
        }

        return new JsonResponse([
            'documents' => $result,
            'hasProcessed' => $hasProcessed,
            'hasKeywordMatched' => $hasKeywordMatched,
        ]);
    }
}
