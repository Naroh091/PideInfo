<?php

namespace App\Controller;

use App\DataTable\Type\AccessRequestTableType;
use App\Entity\AccessRequest;
use App\Entity\CustomDeadline;
use App\Entity\DeadlineHistory;
use App\Entity\Reminder;
use App\Entity\StatusHistory;
use App\Form\AccessRequestType;
use App\Form\CustomDeadlineType;
use App\Repository\AccessRequestListRepository;
use App\Repository\AccessRequestRepository;
use App\Repository\CustomDeadlineRepository;
use App\Repository\StatusHistoryRepository;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitudes')]
#[IsGranted('ROLE_USER')]
class AccessRequestController extends AbstractController
{
    public function __construct(
        private AccessRequestRepository $accessRequestRepository,
        private AccessRequestListRepository $accessRequestListRepository,
        private EntityManagerInterface $entityManager,
        private DataTableFactory $dataTableFactory,
    ) {
    }

    #[Route('', name: 'app_solicitudes_index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $search = $request->query->get('q');
        $listId = $request->query->get('list');

        $table = $this->dataTableFactory->createFromType(
            AccessRequestTableType::class,
            ['status' => $status, 'search' => $search, 'list' => $listId]
        )->handleRequest($request);

        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $lists = $this->accessRequestListRepository->findVisibleToUser($this->getUser());
        $currentList = $listId ? $this->accessRequestListRepository->find($listId) : null;

        $statusCounts = $this->accessRequestRepository->getStatusCounts($this->getUser());
        $appealedCount = $this->accessRequestRepository->countAppealed($this->getUser());
        $totalCount = array_sum($statusCounts);

        return $this->render('solicitudes/index.html.twig', [
            'datatable' => $table,
            'status' => $status,
            'search' => $search,
            'lists' => $lists,
            'currentList' => $currentList,
            'listId' => $listId,
            'statusCounts' => $statusCounts,
            'appealedCount' => $appealedCount,
            'totalCount' => $totalCount,
        ]);
    }

    #[Route('/nueva', name: 'app_solicitudes_new')]
    public function new(Request $request, AccessRequestManager $manager): Response
    {
        $accessRequest = new AccessRequest();
        $form = $this->createForm(AccessRequestType::class, $accessRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$form->get('publicBody')->getData()) {
                $this->addFlash('error', 'Por favor, selecciona o crea un organismo público.');
                return $this->render('solicitudes/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $manager->createRequest($accessRequest, $this->getUser());

            $this->addFlash('success', 'Solicitud creada correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/buscar', name: 'app_solicitudes_search_json', methods: ['GET'])]
    public function searchJson(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $q = $request->query->get('q') ?: null;
        $publicBody = $request->query->get('publicBody') ?: null;

        $dateFrom = null;
        if ($request->query->get('dateFrom')) {
            try { $dateFrom = new \DateTimeImmutable($request->query->get('dateFrom')); } catch (\Exception) {}
        }
        $dateTo = null;
        if ($request->query->get('dateTo')) {
            try { $dateTo = new \DateTimeImmutable($request->query->get('dateTo')); } catch (\Exception) {}
        }

        $requests = $this->accessRequestRepository->searchForLinking(
            $user, $q, $publicBody, $dateFrom, $dateTo
        );

        $result = [];
        foreach ($requests as $ar) {
            $result[] = [
                'id' => (string) $ar->getId(),
                'title' => $ar->getTitle(),
                'publicBody' => ['name' => $ar->getPublicBody()->getName()],
                'externalId' => $ar->getExternalId(),
                'status' => $ar->getStatus(),
                'statusLabel' => $ar->getStatusLabel(),
                'sentAt' => $ar->getSentAt()->format('d/m/Y'),
            ];
        }

        return new JsonResponse(['requests' => $result]);
    }

    #[Route('/{id}', name: 'app_solicitudes_show')]
    #[IsGranted('view', 'accessRequest')]
    public function show(AccessRequest $accessRequest): Response
    {
        return $this->render('solicitudes/show.html.twig', [
            'request' => $accessRequest,
        ]);
    }

    #[Route('/{id}/recordatorio', name: 'app_solicitudes_create_reminder', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function createReminder(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('reminder-' . $accessRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $days = max(1, min(365, (int) $request->request->get('days', 5)));
        $note = trim((string) $request->request->get('note', ''));

        $reminder = new Reminder();
        $reminder->setUser($this->getUser());
        $reminder->setAccessRequest($accessRequest);
        $reminder->setRemindAt(new \DateTimeImmutable('today +' . $days . ' days'));
        if ($note !== '') {
            $reminder->setNote(mb_substr($note, 0, 500));
        }

        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Recordatorio creado para el %s.',
            $reminder->getRemindAt()->format('d/m/Y')
        ));

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/documentos', name: 'app_solicitudes_documents_fragment', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function documentsFragment(AccessRequest $accessRequest): Response
    {
        // Force refresh of the documents collection from DB
        $this->entityManager->refresh($accessRequest);

        return $this->render('solicitudes/_documents_list.html.twig', [
            'request' => $accessRequest,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_solicitudes_edit')]
    #[IsGranted('edit', 'accessRequest')]
    public function edit(Request $request, AccessRequest $accessRequest, AccessRequestManager $manager): Response
    {
        $previousStatus = $accessRequest->getStatus();
        $previousDeadline = $accessRequest->getDeadlineAt();

        $form = $this->createForm(AccessRequestType::class, $accessRequest, [
            'include_status_fields' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$form->get('publicBody')->getData()) {
                $this->addFlash('error', 'Por favor, selecciona o crea un organismo público.');
                return $this->render('solicitudes/edit.html.twig', [
                    'request' => $accessRequest,
                    'form' => $form,
                ]);
            }

            // Handle status change via manager (records StatusHistory)
            $newStatus = $accessRequest->getStatus();
            if ($newStatus !== $previousStatus) {
                // Reset to previous so changeStatus can do the transition properly
                $accessRequest->setStatus($previousStatus);
                $manager->changeStatus($accessRequest, StatusHistory::TYPE_STATUS, $newStatus, 'Cambio manual desde formulario de edición');
            }

            // Handle deadline change (records DeadlineHistory)
            $newDeadline = $accessRequest->getDeadlineAt();
            if ($newDeadline->format('Y-m-d') !== $previousDeadline->format('Y-m-d')) {
                $deadlineHistory = new DeadlineHistory();
                $deadlineHistory->setAccessRequest($accessRequest);
                $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
                $deadlineHistory->setPreviousDeadline($previousDeadline);
                $deadlineHistory->setNewDeadline($newDeadline);
                $deadlineHistory->setReason(DeadlineHistory::REASON_MANUAL);
                $deadlineHistory->setNotes('Plazo modificado manualmente desde formulario de edición');
                $accessRequest->addDeadlineHistory($deadlineHistory);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Solicitud actualizada correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/edit.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/recordatorios/nuevo', name: 'app_solicitudes_deadline_new', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function newDeadline(Request $request, AccessRequest $accessRequest): Response
    {
        $deadline = new CustomDeadline();
        $form = $this->createForm(CustomDeadlineType::class, $deadline);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $accessRequest->addCustomDeadline($deadline);
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio creado correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/deadline_form.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/recordatorios/{deadlineId}/editar', name: 'app_solicitudes_deadline_edit', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function editDeadline(
        Request $request,
        AccessRequest $accessRequest,
        string $deadlineId,
        CustomDeadlineRepository $deadlineRepository
    ): Response {
        $deadline = $deadlineRepository->find($deadlineId);

        if (!$deadline || $deadline->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Recordatorio no encontrado.');
        }

        $form = $this->createForm(CustomDeadlineType::class, $deadline);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio actualizado correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/deadline_form.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
            'deadline' => $deadline,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/recordatorios/{deadlineId}/eliminar', name: 'app_solicitudes_deadline_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function deleteDeadline(
        Request $request,
        AccessRequest $accessRequest,
        string $deadlineId,
        CustomDeadlineRepository $deadlineRepository
    ): Response {
        $deadline = $deadlineRepository->find($deadlineId);

        if (!$deadline || $deadline->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Recordatorio no encontrado.');
        }

        if ($this->isCsrfTokenValid('delete-deadline-' . $deadlineId, $request->request->get('_token'))) {
            $accessRequest->removeCustomDeadline($deadline);
            $this->entityManager->remove($deadline);
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio eliminado correctamente.');
        }

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/historial/{historyId}/eliminar', name: 'app_solicitudes_status_history_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function deleteStatusHistory(
        Request $request,
        AccessRequest $accessRequest,
        string $historyId,
        StatusHistoryRepository $statusHistoryRepository
    ): Response {
        $history = $statusHistoryRepository->find($historyId);

        if (!$history || $history->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Evento no encontrado.');
        }

        if ($this->isCsrfTokenValid('delete-status-history-' . $historyId, $request->request->get('_token'))) {
            $this->entityManager->remove($history);
            $this->entityManager->flush();

            $this->addFlash('success', 'Evento eliminado del historial.');
        }

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/eliminar', name: 'app_solicitudes_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function delete(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('delete-request-' . $accessRequest->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $title = $accessRequest->getTitle();

        $this->entityManager->remove($accessRequest);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Solicitud "%s" eliminada correctamente.', $title));
        return $this->redirectToRoute('app_solicitudes_index');
    }

    #[Route('/{id}/reclamacion/editar', name: 'app_solicitudes_complaint_edit', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function editComplaint(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('complaint-edit-' . $accessRequest->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $complaint = $accessRequest->getComplaint();
        if ($complaint === null) {
            $this->addFlash('error', 'Esta solicitud no tiene reclamación');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $externalId = $request->request->get('externalId');
        $filedAtStr = $request->request->get('filedAt');

        $complaint->setExternalId($externalId ?: null);

        if ($filedAtStr) {
            try {
                $complaint->setFiledAt(new \DateTimeImmutable($filedAtStr));
            } catch (\Exception) {}
        } else {
            $complaint->setFiledAt(null);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Datos de reclamación actualizados.');
        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/estado', name: 'app_solicitudes_change_status', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function changeStatus(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestManager $manager
    ): Response {
        // Verify CSRF token
        if (!$this->isCsrfTokenValid('change-status-' . $accessRequest->getId(), $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Token CSRF inválido'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $statusType = $request->request->get('statusType');
        $newStatus = $request->request->get('newStatus');
        $notes = $request->request->get('notes');

        // Validate required fields
        if (!$statusType || !$newStatus) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Faltan campos requeridos'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Faltan campos requeridos');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        // Attempt to change the status
        $success = $manager->changeStatus($accessRequest, $statusType, $newStatus, $notes);

        if (!$success) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Estado o tipo de estado inválido'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Estado o tipo de estado inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'message' => 'Estado actualizado correctamente']);
        }

        $this->addFlash('success', 'Estado actualizado correctamente');
        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }
}
