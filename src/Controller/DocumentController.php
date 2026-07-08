<?php

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Message\GenerateDocumentEmbeddingsMessage;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Document\DocumentIngestionFilter;
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

    #[Route('', name: 'app_documentos_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;

        $documents = $this->documentRepository->findByUserPaginated($user, $page, $limit);
        $total = $this->documentRepository->countByUser($user);

        return $this->render('documentos/index.html.twig', [
            'documents' => $documents,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'total' => $total,
            'pageSize' => $limit,
        ]);
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

        // Check file size (max 50MB)
        if ($file->getSize() > 50 * 1024 * 1024) {
            return new JsonResponse(['error' => 'El archivo es demasiado grande (máximo 50MB)'], Response::HTTP_BAD_REQUEST);
        }

        // Check file type
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/zip',
            'application/x-zip-compressed',
        ];

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return new JsonResponse(['error' => 'Tipo de archivo no permitido'], Response::HTTP_BAD_REQUEST);
        }

        // Drop tiny images (signature logos, icons, tracking pixels) before they
        // even reach the AI pipeline.
        if (DocumentIngestionFilter::isTinyImage($file->getMimeType(), (int) $file->getSize())) {
            return new JsonResponse([
                'skipped' => true,
                'error' => 'Imagen demasiado pequeña para procesar (probablemente un logo o icono).',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Detect if this is a ZIP file
        $isZipFile = in_array($file->getMimeType(), ['application/zip', 'application/x-zip-compressed']);

        // Get optional access request ID
        $accessRequestId = $request->request->get('accessRequestId');
        $accessRequest = null;
        if ($accessRequestId) {
            $accessRequest = $this->accessRequestRepository->find($accessRequestId);
            if ($accessRequest && !$this->isGranted('view', $accessRequest)) {
                return new JsonResponse(['error' => 'No tienes acceso a esta solicitud'], Response::HTTP_FORBIDDEN);
            }
        }

        try {
            // Reject duplicate uploads (same user, same content). Other ingestion
            // paths (agent webhook, inbound email) already dedupe by contentHash;
            // this closes the manual-upload gap.
            $contentHash = hash_file('sha256', $file->getPathname());
            $existing = $this->documentRepository->findOneBy([
                'uploadedBy' => $user,
                'contentHash' => $contentHash,
            ]);
            if ($existing !== null) {
                $existingRequest = $existing->getAccessRequest();
                return new JsonResponse([
                    'duplicate' => true,
                    'error' => 'Este archivo ya existe en tus documentos.',
                    'existing' => [
                        'id' => (string) $existing->getId(),
                        'name' => $existing->getOriginalFilename(),
                        'createdAt' => $existing->getCreatedAt()?->format('d/m/Y H:i'),
                        'accessRequestId' => $existingRequest?->getId() ? (string) $existingRequest->getId() : null,
                        'downloadUrl' => $this->generateUrl('app_document_download', ['id' => $existing->getId()]),
                    ],
                ], Response::HTTP_CONFLICT);
            }

            // Create document entity
            $document = new Document();
            $document->setUploadedBy($user);
            $document->setAccessRequest($accessRequest);
            $document->setOriginalFilename($file->getClientOriginalName());
            $document->setMimeType($file->getMimeType() ?? 'application/octet-stream');
            $document->setFileSize($file->getSize());
            $document->setContentHash($contentHash);

            // ZIP files are stored as "Other" type and marked as processed (no AI analysis)
            if ($isZipFile) {
                $document->setType(DocumentType::Other);
                $document->setProcessed(true);
            } else {
                $document->setType(DocumentType::Unprocessed);
                $document->setProcessed(false);
            }

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

            // Only dispatch processing for non-ZIP files and when not deferred
            $deferProcessing = $request->request->get('deferProcessing') === '1';
            if (!$deferProcessing && !$isZipFile) {
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
            if ($document->getUploadedBy() !== $user && !$this->isGranted('ROLE_ADMIN')) {
                return new JsonResponse(['error' => 'No tienes acceso a uno de los documentos'], Response::HTTP_FORBIDDEN);
            }
            $documents[] = $document;
        }

        // Reset processing state
        foreach ($documents as $document) {
            $document->setProcessed(false);
            $document->setProcessingError(null);
        }
        $this->entityManager->flush();

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

    #[Route('/orphaned', name: 'app_document_orphaned', methods: ['GET'])]
    public function orphaned(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $docs = $this->documentRepository->findOrphanedByUser($user);

        $result = [];
        foreach ($docs as $document) {
            $aiMetadata = $document->getAiMetadata();
            $result[] = [
                'id' => (string) $document->getId(),
                'name' => $document->getOriginalFilename(),
                'type' => $document->getType(),
                'typeLabel' => $document->getTypeLabel(),
                'summary' => $document->getExtractedText(),
                'createdAt' => $document->getCreatedAt()->format('d/m/Y H:i'),
                'downloadUrl' => $this->generateUrl('app_document_download', ['id' => $document->getId()]),
                'publicBodyName' => $aiMetadata['publicBodyName'] ?? null,
                'documentDate' => $aiMetadata['documentDate'] ?? null,
                'isPdf' => $document->isPdf(),
            ];
        }

        return new JsonResponse(['documents' => $result]);
    }

    #[Route('/{id}/descargar', name: 'app_document_download', methods: ['GET'])]
    public function download(Document $document, Request $request): Response
    {
        // Check access via document's uploader or linked request's ownership
        if (!$this->canAccessDocument($document)) {
            throw $this->createAccessDeniedException('No tienes acceso a este documento');
        }

        if (!$this->documentsStorage->fileExists($document->getStoredFilename())) {
            throw $this->createNotFoundException('Documento no encontrado');
        }

        $storedFilename = $document->getStoredFilename();
        $documentsStorage = $this->documentsStorage;

        // Use inline disposition when ?inline=1 is passed (for iframe embedding)
        $inline = $request->query->getBoolean('inline');
        $disposition = $inline ? 'inline' : sprintf('attachment; filename="%s"', $document->getOriginalFilename());

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
                'Content-Disposition' => $disposition,
                'Content-Length' => $document->getFileSize(),
            ]
        );
    }

    #[Route('/{id}/eliminar', name: 'app_document_delete', methods: ['POST', 'DELETE'])]
    public function delete(Request $request, Document $document): Response
    {
        // Check access via document's uploader or linked request's ownership
        if (!$this->canAccessDocument($document)) {
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

        // Optional explicit return destination (used by the Documentos / Comunicaciones views)
        $redirectRoutes = [
            'documentos' => 'app_documentos_index',
            'comunicaciones' => 'app_comunicaciones_index',
        ];
        $redirect = $request->request->getString('_redirect');
        if (isset($redirectRoutes[$redirect])) {
            return $this->redirectToRoute($redirectRoutes[$redirect]);
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

    #[Route('/{id}/link', name: 'app_document_link', methods: ['POST'])]
    public function linkToRequest(
        Request $request,
        Document $document,
        AccessRequestManager $accessRequestManager
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if ($document->getUploadedBy() !== $user) {
            return new JsonResponse(['error' => 'No tienes acceso a este documento'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $accessRequestId = $data['accessRequestId'] ?? null;

        if (!$accessRequestId) {
            return new JsonResponse(['error' => 'Falta el ID de la solicitud'], Response::HTTP_BAD_REQUEST);
        }

        $accessRequest = $this->accessRequestRepository->find($accessRequestId);
        if (!$accessRequest || !$this->isGranted('edit', $accessRequest)) {
            return new JsonResponse(['error' => 'Solicitud no encontrada'], Response::HTTP_NOT_FOUND);
        }

        // Link the document
        $document->setAccessRequest($accessRequest);
        $document->setMatchMethod(Document::MATCH_REFERENCE);

        // If the document has a reference number different from the request's externalId,
        // add it as an alternative reference for future automatic matching
        $aiMetadata = $document->getAiMetadata();
        $docRefNumber = $aiMetadata['referenceNumber'] ?? null;
        if ($docRefNumber && $docRefNumber !== $accessRequest->getExternalId()
            && !in_array($docRefNumber, $accessRequest->getAlternativeReferences(), true)) {
            $accessRequest->addAlternativeReference($docRefNumber);
        }

        // Apply document type effects (extension, status change, etc.)
        $documentType = $document->getType();
        $analysis = $aiMetadata ?? [];
        $analysis['documentType'] = $documentType;

        // Handle status/deadline effects based on document type
        if ($documentType === DocumentType::Extension && ($analysis['isExtension'] ?? false)) {
            $explicitNewDeadline = null;
            if (!empty($analysis['newDeadlineDate'])) {
                try {
                    $explicitNewDeadline = new \DateTimeImmutable($analysis['newDeadlineDate']);
                } catch (\Exception) {}
            }
            $accessRequestManager->extendDeadlineByLaw($accessRequest, $document, $explicitNewDeadline);
        } elseif ($documentType === DocumentType::Response) {
            $statusMap = [
                'concedida' => AccessRequest::STATUS_GRANTED,
                'denegada' => AccessRequest::STATUS_DENIED,
            ];
            $newStatus = $statusMap[$analysis['status'] ?? ''] ?? null;
            if ($newStatus && $accessRequest->getStatus() !== $newStatus) {
                $accessRequestManager->changeStatus(
                    $accessRequest,
                    'status',
                    $newStatus,
                    $analysis['summary'] ?? 'Resolución enlazada manualmente'
                );
            }
        }

        $this->entityManager->flush();

        // Document is being attached to a (possibly new) request; refresh its
        // embeddings so the accessRequestId metadata in ai_documents is current.
        if ($document->getExtractedText()) {
            $this->messageBus->dispatch(new GenerateDocumentEmbeddingsMessage($document->getId()));
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Documento enlazado correctamente',
            'accessRequest' => [
                'id' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
                'url' => $this->generateUrl('app_solicitudes_show', ['id' => $accessRequest->getId()]),
            ],
        ]);
    }

    #[Route('/{id}/editar', name: 'app_document_edit', methods: ['POST'])]
    public function edit(Request $request, Document $document): JsonResponse
    {
        // Same access rule as download/delete (uploader, admin, or request voter).
        if (!$this->canAccessDocument($document)) {
            return new JsonResponse(['error' => 'No tienes acceso a este documento'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid('edit-document', $data['_token'] ?? '')) {
            return new JsonResponse(['error' => 'Token CSRF inválido'], Response::HTTP_FORBIDDEN);
        }

        $typeChanged = false;

        // Rename: empty string clears the override and falls back to the derived name.
        if (array_key_exists('name', $data)) {
            $document->setCustomName(is_string($data['name']) ? $data['name'] : null);
        }

        // Reclassify. Changing the type moves the document between the request /
        // complaint phase (the phase is derived from DocumentType::isComplaintRelated()).
        if (array_key_exists('type', $data) && $data['type'] !== null && $data['type'] !== '') {
            $newType = DocumentType::tryFrom((string) $data['type']);
            if ($newType === null || $newType === DocumentType::Unprocessed) {
                return new JsonResponse(['error' => 'Tipo de documento no válido'], Response::HTTP_BAD_REQUEST);
            }
            if ($newType !== $document->getType()) {
                $document->setType($newType);
                $typeChanged = true;
            }
        }

        // This is a manual metadata correction only: unlike linkToRequest(), we do
        // NOT re-run status/deadline side effects, which would double-apply them.
        // The manual type persists a later "Reprocesar" because ProcessDocumentHandler
        // treats any non-Unprocessed type as a preassignment that wins over the AI.
        $this->entityManager->flush();

        // A type change alters the document's semantics; refresh embeddings so the
        // ai_documents metadata stays current (mirrors linkToRequest()).
        if ($typeChanged && $document->getExtractedText()) {
            $this->messageBus->dispatch(new GenerateDocumentEmbeddingsMessage($document->getId()));
        }

        return new JsonResponse([
            'success' => true,
            'document' => [
                'id' => (string) $document->getId(),
                'name' => $document->getDisplayFilename(),
                'type' => $document->getType()->value,
                'typeLabel' => $document->getTypeLabel(),
            ],
        ]);
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

            $aiMetadata = $document->getAiMetadata();
            $result[] = [
                'id' => (string) $document->getId(),
                'name' => $document->getOriginalFilename(),
                'processed' => $isProcessed,
                'type' => $document->getType(),
                'typeLabel' => $document->getTypeLabel(),
                'matchMethod' => $document->getMatchMethod(),
                'error' => $document->getProcessingError(),
                'downloadUrl' => $this->generateUrl('app_document_download', ['id' => $document->getId()]),
                'publicBodyName' => $aiMetadata['publicBodyName'] ?? null,
                'documentDate' => $aiMetadata['documentDate'] ?? null,
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

    #[Route('/solicitud/{id}/descargar-todo', name: 'app_document_download_all', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function downloadAll(AccessRequest $accessRequest): Response
    {
        if (!$this->isGranted('view', $accessRequest)) {
            throw $this->createAccessDeniedException('No tienes acceso a esta solicitud');
        }

        $documents = $accessRequest->getDocuments();
        if ($documents->isEmpty()) {
            throw $this->createNotFoundException('No hay documentos para descargar');
        }

        $documentsStorage = $this->documentsStorage;

        $zipFilename = sprintf(
            '%s - documentos.zip',
            preg_replace('/[^a-zA-Z0-9áéíóúñÁÉÍÓÚÑ\s\-_]/u', '', $accessRequest->getTitle())
        );

        return new StreamedResponse(
            function () use ($documents, $documentsStorage): void {
                $zip = new \ZipArchive();
                $tmpFile = tempnam(sys_get_temp_dir(), 'docs_');
                $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

                $usedNames = [];
                foreach ($documents as $document) {
                    if (!$documentsStorage->fileExists($document->getStoredFilename())) {
                        continue;
                    }

                    // Ensure unique filenames in the ZIP
                    $name = $document->getDisplayFilename();
                    if (isset($usedNames[$name])) {
                        $usedNames[$name]++;
                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                        $base = pathinfo($name, PATHINFO_FILENAME);
                        $name = $ext
                            ? sprintf('%s (%d).%s', $base, $usedNames[$name], $ext)
                            : sprintf('%s (%d)', $base, $usedNames[$name]);
                    } else {
                        $usedNames[$name] = 1;
                    }

                    $content = $documentsStorage->read($document->getStoredFilename());
                    $zip->addFromString($name, $content);
                }

                $zip->close();

                readfile($tmpFile);
                unlink($tmpFile);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $zipFilename),
            ]
        );
    }

    private function canAccessDocument(Document $document): bool
    {
        /** @var User $user */
        $user = $this->getUser();

        // Admins can access any document
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Direct uploader always has access
        if ($document->getUploadedBy()->getId()->equals($user->getId())) {
            return true;
        }

        // If the document is linked to a request, check via the voter
        $accessRequest = $document->getAccessRequest();
        if ($accessRequest !== null) {
            return $this->isGranted('view', $accessRequest);
        }

        return false;
    }
}
