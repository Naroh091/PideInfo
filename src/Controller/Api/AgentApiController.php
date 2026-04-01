<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AgentWebhookProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent')]
class AgentApiController extends AbstractController
{
    #[Route('/pending-refs', name: 'api_agent_pending_refs', methods: ['GET'])]
    public function pendingRefs(AccessRequestRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $grouped = $repository->findWithPendingPortalNotifications($user);

        return new JsonResponse([
            'portal'  => array_values(array_filter(array_map(
                static fn(array $item) => $item['request']->getExternalId(),
                $grouped['portal']
            ))),
            'consejo' => array_values(array_filter(array_map(
                static fn(array $item) => $item['request']->getExternalId(),
                $grouped['consejo']
            ))),
            'dehu'    => array_keys($user->getPendingDehuNotifications()),
        ]);
    }

    #[Route('/me', name: 'api_agent_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'firstName' => $user->getFirstName(),
        ]);
    }

    #[Route('/webhook', name: 'api_agent_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        AgentWebhookProcessor $processor,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.AGENT')]
        RateLimiterFactory $rateLimiter,
    ): JsonResponse {
        // Rate limit by IP
        $limiter = $rateLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Check payload size
        $maxPayloadSize = 50 * 1024 * 1024; // 50MB
        if ($request->headers->get('Content-Length', 0) > $maxPayloadSize) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        return $processor->process($user, $data);
    }
}
