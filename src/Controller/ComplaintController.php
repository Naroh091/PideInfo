<?php

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Enum\DocumentType;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Complaint\SuccessAnalyzer;
use App\Service\Document\PdfGenerator;
use App\Service\Document\WordGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitudes/{id}/reclamacion')]
#[IsGranted('ROLE_USER')]
class ComplaintController extends AbstractController
{
    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly SuccessAnalyzer $successAnalyzer,
        private readonly PdfGenerator $pdfGenerator,
        private readonly WordGenerator $wordGenerator,
        private readonly FilesystemOperator $documentsStorage,
    ) {
    }

    #[Route('', name: 'app_complaint_generate', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function generate(AccessRequest $accessRequest): Response
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            $this->addFlash('error', 'Solo se pueden generar reclamaciones para solicitudes denegadas o sin respuesta.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $existingComplaint = null;
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Complaint) {
                $existingComplaint = $document;
                break;
            }
        }

        return $this->render('complaint/generate.html.twig', [
            'request' => $accessRequest,
            'existingComplaint' => $existingComplaint,
        ]);
    }

    #[Route('/generar', name: 'app_complaint_create', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function create(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Solo se pueden generar reclamaciones para solicitudes denegadas o sin respuesta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $draft = $this->complaintGenerator->generate($accessRequest);

            $document = $this->complaintGenerator->saveComplaint($accessRequest, $draft);

            $previewHtml = $this->renderView('complaint/_preview.html.twig', [
                'draft' => $draft,
                'request' => $accessRequest,
                'document' => $document,
            ]);

            return new JsonResponse([
                'success' => true,
                'preview' => $previewHtml,
                'documentId' => $document->getId()->toRfc4122(),
                'successAnalysis' => $draft->successAnalysis?->toArray(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al generar la reclamación: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/analisis', name: 'app_complaint_analyze', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function analyze(AccessRequest $accessRequest): JsonResponse
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Solo se pueden analizar solicitudes denegadas o sin respuesta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $analysis = $this->successAnalyzer->analyze($accessRequest);

            return new JsonResponse([
                'success' => true,
                'analysis' => $analysis->toArray(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al analizar la probabilidad: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/pdf', name: 'app_complaint_pdf', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadPdf(AccessRequest $accessRequest): Response
    {
        $complaintDocument = null;
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Complaint) {
                $complaintDocument = $document;
                break;
            }
        }

        if (!$complaintDocument) {
            $this->addFlash('error', 'No hay reclamación generada para esta solicitud.');
            return $this->redirectToRoute('app_complaint_generate', ['id' => $accessRequest->getId()]);
        }

        $metadata = $complaintDocument->getAiMetadata();
        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $this->getComplaintContent($complaintDocument),
            'transparencyCouncil' => $metadata['transparencyCouncil'] ?? '',
            'applicableLaw' => $metadata['applicableLaw'] ?? '',
            'citedResolutions' => $metadata['citedResolutions'] ?? [],
            'citedCriteria' => $metadata['citedCriteria'] ?? [],
            'successAnalysis' => $metadata['successAnalysis'] ?? null,
        ]);

        $pdfContent = $this->pdfGenerator->generateComplaintPdf($accessRequest, $draft);

        $filename = sprintf(
            'reclamacion_%s_%s.pdf',
            $accessRequest->getPublicBody()->getName(),
            (new \DateTime())->format('Y-m-d')
        );
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/word', name: 'app_complaint_word', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadWord(AccessRequest $accessRequest): Response
    {
        $complaintDocument = null;
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Complaint) {
                $complaintDocument = $document;
                break;
            }
        }

        if (!$complaintDocument) {
            $this->addFlash('error', 'No hay reclamación generada para esta solicitud.');
            return $this->redirectToRoute('app_complaint_generate', ['id' => $accessRequest->getId()]);
        }

        $metadata = $complaintDocument->getAiMetadata();
        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $this->getComplaintContent($complaintDocument),
            'transparencyCouncil' => $metadata['transparencyCouncil'] ?? '',
            'applicableLaw' => $metadata['applicableLaw'] ?? '',
            'citedResolutions' => $metadata['citedResolutions'] ?? [],
            'citedCriteria' => $metadata['citedCriteria'] ?? [],
            'successAnalysis' => $metadata['successAnalysis'] ?? null,
        ]);

        $wordContent = $this->wordGenerator->generateComplaintWord($accessRequest, $draft);

        $filename = sprintf(
            'reclamacion_%s_%s.docx',
            $accessRequest->getPublicBody()->getName(),
            (new \DateTime())->format('Y-m-d')
        );
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return new Response($wordContent, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function getComplaintContent(\App\Entity\Document $document): string
    {
        try {
            return $this->documentsStorage->read($document->getStoredFilename());
        } catch (\Exception $e) {
            return '';
        }
    }
}
