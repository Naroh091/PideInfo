<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Service\AccessRequest\Batch\BatchPlaceholders;
use App\Service\AccessRequest\Batch\BatchVersionGenerator;
use App\Service\AccessRequest\Batch\DraftBatchOverview;
use App\Service\AccessRequest\Batch\DraftBatchTemplateStore;
use App\Service\AI\Chat\ChatHistoryStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Unified template of a multi-recipient batch ("plantilla común"): the page
 * where the common text is drafted (with its own assistant thread), its
 * autosave, and the generation of each recipient's version from it.
 *
 * Flow: plantilla común → una versión por organismo → cada versión se ajusta
 * en su pestaña (con su propio hilo) → envío del lote. See
 * docs/request-workflow.md → "Solicitud unificada para varios organismos".
 */
#[Route('/solicitudes/nueva/realizar/lote/{batchId}', requirements: ['batchId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class DraftBatchController extends AbstractController
{
    public function __construct(
        private readonly DraftBatchOverview $overview,
        private readonly DraftBatchTemplateStore $templateStore,
        private readonly ChatHistoryStore $chatHistoryStore,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/plantilla', name: 'app_solicitudes_batch_template', methods: ['GET'])]
    public function template(string $batchId): Response
    {
        $user = $this->currentUser();
        $siblings = $this->siblingsOr404($batchId, $user);
        $lead = $siblings[0];

        $chatHistory = $this->chatHistoryStore->load(ChatHistoryStore::threadIdForBatch($batchId), $user);

        $placeholderValues = [];
        foreach ($siblings as $sibling) {
            $placeholderValues[] = [
                'request' => $sibling,
                'values' => BatchPlaceholders::valuesFor($sibling),
            ];
        }

        return $this->render('asistente/conversacion.html.twig', [
            'flow' => 'request',
            'templateMode' => true,
            'request' => $lead,
            'siblings' => $siblings,
            'batchId' => $batchId,
            'batch' => $this->overview->navigation($batchId, $siblings, $user),
            'chatHistory' => $chatHistory,
            'placeholderCatalog' => BatchPlaceholders::catalog(),
            'placeholderValues' => $placeholderValues,
        ]);
    }

    #[Route('/plantilla/autoguardar', name: 'app_solicitudes_batch_template_autosave', methods: ['POST'])]
    public function autosave(Request $request, string $batchId): JsonResponse
    {
        $user = $this->currentUser();
        $this->siblingsOr404($batchId, $user);

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $current = $this->templateStore->find($batchId, $user);

        $savedAt = $this->templateStore->save(
            $batchId,
            $user,
            isset($payload['title']) ? mb_substr((string) $payload['title'], 0, 80) : $current->title,
            isset($payload['expone']) ? (string) $payload['expone'] : $current->expone,
            isset($payload['solicita']) ? (string) $payload['solicita'] : $current->solicita,
        );

        return new JsonResponse(['savedAt' => $savedAt->format(\DateTimeInterface::ATOM)]);
    }

    /**
     * Generates (or regenerates) ONE recipient's version from the template. The
     * page calls it once per selected recipient so it can show progress row by
     * row; regenerating a version the user adjusted requires `overwrite: true`
     * (the page asks first).
     */
    #[Route('/versiones/{id}', name: 'app_solicitudes_batch_version_generate', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function generateVersion(
        Request $request,
        string $batchId,
        AccessRequest $accessRequest,
        BatchVersionGenerator $generator,
    ): JsonResponse {
        $user = $this->currentUser();
        $siblings = $this->siblingsOr404($batchId, $user);
        $inBatch = array_filter($siblings, static fn (AccessRequest $s) => $s->getId()->equals($accessRequest->getId()));
        if ($inBatch === []) {
            throw $this->createNotFoundException('not_in_batch');
        }

        $template = $this->templateStore->find($batchId, $user);
        if ($template->isEmpty()) {
            return new JsonResponse(['error' => 'empty_template'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $state = DraftBatchOverview::classify($accessRequest);
        if ($state === DraftBatchOverview::STATE_SENT) {
            return new JsonResponse(['error' => 'already_sent'], Response::HTTP_CONFLICT);
        }
        $payload = json_decode($request->getContent(), true);
        $overwrite = is_array($payload) && (bool) ($payload['overwrite'] ?? false);
        if (DraftBatchOverview::regenerationOverwritesWork($state) && !$overwrite) {
            return new JsonResponse(['error' => 'would_overwrite', 'state' => $state], Response::HTTP_CONFLICT);
        }

        $result = $generator->generate($accessRequest, $template, $user);
        $this->entityManager->flush();

        $row = $this->overview->row($accessRequest, $template);

        return new JsonResponse([
            'id' => $accessRequest->getId()->toRfc4122(),
            'state' => $row['state'],
            'label' => $row['label'],
            'ready' => $row['ready'],
            'fallback' => $result['fallback'],
            'url' => $this->generateUrl('app_solicitudes_realizar_draft', ['id' => $accessRequest->getId()]),
        ]);
    }

    /**
     * @return list<AccessRequest>
     */
    private function siblingsOr404(string $batchId, User $user): array
    {
        if (!Uuid::isValid($batchId)) {
            throw $this->createNotFoundException('batch_not_found');
        }
        $siblings = $this->overview->ownedSiblings($batchId, $user);
        if (count($siblings) < 2) {
            throw $this->createNotFoundException('batch_not_found');
        }

        return $siblings;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
