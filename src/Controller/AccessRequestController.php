<?php

namespace App\Controller;

use App\DataTable\Type\AccessRequestTableType;
use App\Entity\AccessRequest;
use App\Form\AccessRequestType;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        $table = $this->dataTableFactory->createFromType(
            AccessRequestTableType::class,
            ['status' => $status]
        )->handleRequest($request);

        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('solicitudes/index.html.twig', [
            'datatable' => $table,
            'status' => $status,
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
}
