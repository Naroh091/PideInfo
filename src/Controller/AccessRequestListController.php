<?php

namespace App\Controller;

use App\Entity\AccessRequestList;
use App\Entity\AccessRequestListItem;
use App\Form\AccessRequestListType;
use App\Repository\AccessRequestListItemRepository;
use App\Repository\AccessRequestListRepository;
use App\Repository\AccessRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/listas')]
#[IsGranted('ROLE_USER')]
class AccessRequestListController extends AbstractController
{
    public function __construct(
        private AccessRequestListRepository $listRepository,
        private AccessRequestListItemRepository $listItemRepository,
        private AccessRequestRepository $accessRequestRepository,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('', name: 'app_listas_index')]
    public function index(): Response
    {
        $lists = $this->listRepository->findVisibleToUser($this->getUser());

        return $this->render('listas/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    #[Route('/nueva', name: 'app_listas_new')]
    public function new(Request $request): Response
    {
        $list = new AccessRequestList();
        $user = $this->getUser();
        $hasOrg = $user->getOrganization() !== null;

        $form = $this->createForm(AccessRequestListType::class, $list, [
            'show_visibility' => $hasOrg,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $list->setUser($user);

            if ($list->getVisibility() === AccessRequestList::VISIBILITY_SHARED && $hasOrg) {
                $list->setOrganization($user->getOrganization());
            }

            $this->entityManager->persist($list);
            $this->entityManager->flush();

            $this->addFlash('success', 'Lista creada correctamente.');
            return $this->redirectToRoute('app_listas_show', ['id' => $list->getId()]);
        }

        return $this->render('listas/form.html.twig', [
            'form' => $form,
            'list' => null,
        ]);
    }

    #[Route('/para-solicitud/{requestId}', name: 'app_listas_for_request', methods: ['GET'])]
    public function forRequest(string $requestId): JsonResponse
    {
        try {
            $requestUuid = Uuid::fromString($requestId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'ID inválido'], Response::HTTP_BAD_REQUEST);
        }

        $accessRequest = $this->accessRequestRepository->find($requestUuid);
        if (!$accessRequest) {
            return new JsonResponse(['error' => 'Solicitud no encontrada'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('view', $accessRequest);

        $lists = $this->listRepository->findVisibleToUser($this->getUser());

        $result = [];
        foreach ($lists as $list) {
            $inList = $this->listItemRepository->findByListAndAccessRequest($list->getId(), $requestUuid) !== null;
            $canEdit = $this->isGranted('edit', $list);

            $result[] = [
                'id' => (string) $list->getId(),
                'name' => $list->getName(),
                'color' => $list->getColor(),
                'visibility' => $list->getVisibility(),
                'visibilityLabel' => $list->getVisibilityLabel(),
                'inList' => $inList,
                'canEdit' => $canEdit,
                'csrfToken' => $canEdit ? $this->csrfTokenManager->getToken('list-manage-' . $list->getId())->getValue() : null,
            ];
        }

        return new JsonResponse(['lists' => $result]);
    }

    #[Route('/{id}', name: 'app_listas_show')]
    #[IsGranted('view', 'list')]
    public function show(AccessRequestList $list): Response
    {
        return $this->render('listas/show.html.twig', [
            'list' => $list,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_listas_edit')]
    #[IsGranted('edit', 'list')]
    public function edit(Request $request, AccessRequestList $list): Response
    {
        $user = $this->getUser();
        $hasOrg = $user->getOrganization() !== null;

        $form = $this->createForm(AccessRequestListType::class, $list, [
            'show_visibility' => $hasOrg,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($list->getVisibility() === AccessRequestList::VISIBILITY_SHARED && $hasOrg) {
                $list->setOrganization($user->getOrganization());
            } elseif ($list->getVisibility() === AccessRequestList::VISIBILITY_PRIVATE) {
                $list->setOrganization(null);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Lista actualizada correctamente.');
            return $this->redirectToRoute('app_listas_show', ['id' => $list->getId()]);
        }

        return $this->render('listas/form.html.twig', [
            'form' => $form,
            'list' => $list,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_listas_delete', methods: ['POST'])]
    #[IsGranted('edit', 'list')]
    public function delete(Request $request, AccessRequestList $list): Response
    {
        if (!$this->isCsrfTokenValid('delete-list-' . $list->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_listas_show', ['id' => $list->getId()]);
        }

        $name = $list->getName();

        $this->entityManager->remove($list);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Lista "%s" eliminada correctamente.', $name));
        return $this->redirectToRoute('app_listas_index');
    }

    #[Route('/{id}/solicitudes/agregar', name: 'app_listas_add_request', methods: ['POST'])]
    #[IsGranted('edit', 'list')]
    public function addRequest(Request $request, AccessRequestList $list): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $requestId = $data['requestId'] ?? null;
        $token = $data['_token'] ?? null;

        if (!$this->isCsrfTokenValid('list-manage-' . $list->getId(), $token)) {
            return new JsonResponse(['error' => 'Token CSRF inválido'], Response::HTTP_FORBIDDEN);
        }

        if (!$requestId) {
            return new JsonResponse(['error' => 'ID de solicitud requerido'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $requestUuid = Uuid::fromString($requestId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'ID de solicitud inválido'], Response::HTTP_BAD_REQUEST);
        }

        $accessRequest = $this->accessRequestRepository->find($requestUuid);
        if (!$accessRequest) {
            return new JsonResponse(['error' => 'Solicitud no encontrada'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted('view', $accessRequest);

        // Check if already in list
        $existing = $this->listItemRepository->findByListAndAccessRequest($list->getId(), $requestUuid);
        if ($existing) {
            return new JsonResponse(['error' => 'La solicitud ya está en esta lista'], Response::HTTP_CONFLICT);
        }

        $maxPosition = $this->listItemRepository->findMaxPosition($list);

        $item = new AccessRequestListItem();
        $item->setList($list);
        $item->setAccessRequest($accessRequest);
        $item->setPosition($maxPosition + 1);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Solicitud añadida a la lista']);
    }

    #[Route('/{id}/solicitudes/quitar', name: 'app_listas_remove_request', methods: ['POST'])]
    #[IsGranted('edit', 'list')]
    public function removeRequest(Request $request, AccessRequestList $list): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $requestId = $data['requestId'] ?? null;
        $token = $data['_token'] ?? null;

        if (!$this->isCsrfTokenValid('list-manage-' . $list->getId(), $token)) {
            return new JsonResponse(['error' => 'Token CSRF inválido'], Response::HTTP_FORBIDDEN);
        }

        if (!$requestId) {
            return new JsonResponse(['error' => 'ID de solicitud requerido'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $requestUuid = Uuid::fromString($requestId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'ID de solicitud inválido'], Response::HTTP_BAD_REQUEST);
        }

        $item = $this->listItemRepository->findByListAndAccessRequest($list->getId(), $requestUuid);
        if (!$item) {
            return new JsonResponse(['error' => 'La solicitud no está en esta lista'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Solicitud eliminada de la lista']);
    }
}
