<?php

namespace App\Controller;

use App\Entity\UserNotification;
use App\Repository\UserNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    #[Route('/notificaciones', name: 'app_notificaciones_index')]
    public function index(Request $request, UserNotificationRepository $repository): Response
    {
        $user = $this->getUser();
        $type = $request->query->get('type');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;

        $notifications = $repository->findByUser($user, $type, $page, $limit);
        $total = $repository->countByUser($user, $type);

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
            'currentType' => $type,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'total' => $total,
            'types' => [
                UserNotification::TYPE_DOCUMENT_IMPORTED => 'Documentos importados',
                UserNotification::TYPE_STATUS_CHANGED => 'Cambios de estado',
                UserNotification::TYPE_REQUEST_CREATED => 'Solicitudes creadas',
                UserNotification::TYPE_NOTIFICATION_ACCEPTED => 'Notificaciones aceptadas',
                UserNotification::TYPE_COMMUNICATION_ACCEPTED => 'Comunicaciones aceptadas',
            ],
        ]);
    }

    #[Route('/notificaciones/{id}/leer', name: 'app_notificaciones_read')]
    public function read(UserNotification $notification, EntityManagerInterface $em): Response
    {
        if ($notification->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (!$notification->isRead()) {
            $notification->markAsRead();
            $em->flush();
        }

        if ($notification->getAccessRequest()) {
            return $this->redirectToRoute('app_solicitudes_show', [
                'id' => $notification->getAccessRequest()->getId(),
            ]);
        }

        return $this->redirectToRoute('app_notificaciones_index');
    }

    #[Route('/notificaciones/leer-todas', name: 'app_notificaciones_read_all', methods: ['POST'])]
    public function readAll(Request $request, UserNotificationRepository $repository): Response
    {
        if (!$this->isCsrfTokenValid('read_all_notifications', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $repository->markAllAsReadForUser($this->getUser());

        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_notificaciones_index');
    }
}
