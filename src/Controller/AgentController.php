<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AgentTaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AgentController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AgentTaskRepository $tasks,
    ) {
    }

    #[Route('/perfil/agente', name: 'app_agente', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $estado = (string) $request->query->get('estado', '');
        if (!array_key_exists($estado, AgentTaskRepository::STATUS_GROUPS)) {
            $estado = null;
        }

        $page = max(1, $request->query->getInt('pagina', 1));
        $result = $this->tasks->findForUserPaginated($user, $estado, $page, self::PER_PAGE);

        // Una página fuera de rango pintaría el estado vacío ("aún no hay
        // tareas") sobre un historial que sí tiene tareas. Volvemos a la primera.
        if ($result['items'] === [] && $page > 1 && $result['total'] > 0) {
            return $this->redirectToRoute('app_agente', $estado !== null ? ['estado' => $estado] : []);
        }

        return $this->render('agente/index.html.twig', [
            'tasks' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'estado' => $estado,
            'counts' => $this->tasks->countByStatusGroupForUser($user),
            'agent' => [
                'connected' => $user->isAgentConnected(),
                'issuedAt' => $user->getAgentTokenIssuedAt(),
                'invalidatedAt' => $user->getAgentTokensInvalidatedAt(),
            ],
        ]);
    }
}
