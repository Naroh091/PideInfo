<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Consult\ConsultDocumentPersister;
use App\Service\Document\CitationFootnoteFormatter;
use App\Service\Document\PdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints of the free-consultation chat that are NOT streaming: save a
 * generated document into the dossier, and render it as a PDF. The streaming
 * turn itself lives in {@see AssistantChatController::consult()}.
 */
final class ConsultController extends AbstractController
{
    /**
     * Save the current chat-generated document into the expediente. Routes by
     * the (agent-suggested, user-editable) doc type:
     *   - Complaint          → official complaint draft (dedicated pipeline).
     *   - AlegationResponse  → official alegation-response document.
     *   - everything else    → inert dossier document (no state effects).
     */
    #[Route('/consulta/{id}/guardar', name: 'app_consult_save', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function save(
        Request $request,
        AccessRequest $accessRequest,
        ConsultDocumentPersister $persister,
        ComplaintGenerator $complaintGenerator,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'auth_required'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        $html = trim((string) ($data['html'] ?? ''));
        if ($html === '') {
            return new JsonResponse(['error' => 'No hay documento que guardar.'], Response::HTTP_BAD_REQUEST);
        }

        $type = DocumentType::tryFrom((string) ($data['docType'] ?? ''));
        if ($type === null || !in_array($type, DocumentType::consultSavable(), true)) {
            $type = DocumentType::Other;
        }
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 255);
        $overwrite = (bool) ($data['overwrite'] ?? false);
        $applicableLaw = $accessRequest->getApplicableLaw()?->getShortCode() ?? '';

        // Reclamación → borrador oficial (lo recoge getComplaintDraftDocument y
        // habilita "presentar vía agente"). Confirmar antes de pisar uno existente.
        if ($type === DocumentType::Complaint) {
            if ($accessRequest->getComplaintDraftDocument() !== null && !$overwrite) {
                return new JsonResponse([
                    'needsConfirm' => true,
                    'message' => 'Ya existe un borrador de reclamación en el expediente. ¿Quieres sustituirlo por este?',
                ]);
            }
            $draft = ComplaintDraft::fromArray(['content' => $html, 'applicableLaw' => $applicableLaw]);
            $document = $complaintGenerator->saveComplaint($accessRequest, $draft, ['origin' => 'consult']);

            return new JsonResponse(['success' => true, 'documentId' => $document->getId()->toRfc4122()]);
        }

        // Respuesta a alegaciones → documento oficial del flujo dedicado
        // (text/plain): convertimos el HTML del lienzo a texto.
        if ($type === DocumentType::AlegationResponse) {
            $draft = ComplaintDraft::fromArray(['content' => $this->htmlToPlain($html), 'applicableLaw' => $applicableLaw]);
            $document = $complaintGenerator->saveAlegationResponse($accessRequest, $draft);

            return new JsonResponse(['success' => true, 'documentId' => $document->getId()->toRfc4122()]);
        }

        // Resto (subsanación, otro…) → documento inerte del expediente.
        $document = $persister->persist($accessRequest, $type, $html, $title, $user);

        return new JsonResponse(['success' => true, 'documentId' => $document->getId()->toRfc4122()]);
    }

    /**
     * Render the current chat document to a PDF (neutral stationery + cited
     * footnotes), streamed as an attachment. Mirrors the complaint PDF-from-HTML
     * path but with a heading-agnostic template.
     */
    #[Route('/consulta/{id}/pdf', name: 'app_consult_pdf', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function pdf(
        Request $request,
        AccessRequest $accessRequest,
        CitationFootnoteFormatter $footnoteFormatter,
        PdfGenerator $pdfGenerator,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $html = is_array($data) ? trim((string) ($data['html'] ?? '')) : '';
        if ($html === '') {
            return new JsonResponse(['error' => 'No hay contenido.'], Response::HTTP_BAD_REQUEST);
        }

        $sources = $accessRequest->getMetadataValue('consult_cited_sources');
        $formatted = $footnoteFormatter->formatHtml($html, is_array($sources) ? $sources : []);

        $renderedHtml = $this->renderView('pdf/consult_document.html.twig', [
            'accessRequest' => $accessRequest,
            'content_html' => $formatted['html'],
            'footnotes' => $formatted['notes'],
        ]);
        $pdfContent = $pdfGenerator->generateFromHtml($renderedHtml);

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', sprintf(
            'documento_%s_%s.pdf',
            $accessRequest->getPublicBody()->getName(),
            (new \DateTime())->format('Y-m-d')
        ));

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function htmlToPlain(string $html): string
    {
        $s = preg_replace('~</(p|div|h[1-6]|li)>~i', "\n\n", $html);
        $s = preg_replace('~<br\s*/?>~i', "\n", (string) $s);
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/\n{3,}/", "\n\n", (string) $s);

        return trim((string) $s);
    }
}
