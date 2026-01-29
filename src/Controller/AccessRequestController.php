<?php

namespace App\Controller;

use App\DataTable\Type\AccessRequestTableType;
use App\Entity\AccessRequest;
use App\Entity\CustomDeadline;
use App\Form\AccessRequestType;
use App\Form\CustomDeadlineType;
use App\Repository\AccessRequestRepository;
use App\Repository\CustomDeadlineRepository;
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
        private EntityManagerInterface $entityManager,
        private DataTableFactory $dataTableFactory,
    ) {
    }

    #[Route('', name: 'app_solicitudes_index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $search = $request->query->get('q');

        $table = $this->dataTableFactory->createFromType(
            AccessRequestTableType::class,
            ['status' => $status, 'search' => $search]
        )->handleRequest($request);

        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('solicitudes/index.html.twig', [
            'datatable' => $table,
            'status' => $status,
            'search' => $search,
        ]);
    }

    #[Route('/nueva', name: 'app_solicitudes_new')]
    public function new(Request $request, AccessRequestManager $manager): Response
    {
        $accessRequest = new AccessRequest();
        $form = $this->createForm(AccessRequestType::class, $accessRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$accessRequest->getPublicBody()) {
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

    #[Route('/{id}', name: 'app_solicitudes_show')]
    #[IsGranted('view', 'accessRequest')]
    public function show(AccessRequest $accessRequest): Response
    {
        return $this->render('solicitudes/show.html.twig', [
            'request' => $accessRequest,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_solicitudes_edit')]
    #[IsGranted('edit', 'accessRequest')]
    public function edit(Request $request, AccessRequest $accessRequest): Response
    {
        $form = $this->createForm(AccessRequestType::class, $accessRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$accessRequest->getPublicBody()) {
                $this->addFlash('error', 'Por favor, selecciona o crea un organismo público.');
                return $this->render('solicitudes/edit.html.twig', [
                    'request' => $accessRequest,
                    'form' => $form,
                ]);
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
