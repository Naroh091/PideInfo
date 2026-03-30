<?php

namespace App\Controller\Webhook;

use App\Repository\UserRepository;
use App\Service\AgentWebhookProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class AgentController extends AbstractController
{
    private const MAX_PAYLOAD_SIZE = 50 * 1024 * 1024; // 50MB

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AgentWebhookProcessor $processor,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'AGENT_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        #[Autowire(service: 'limiter.AGENT')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    #[Route('/webhook/agent', name: 'app_webhook_agent', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Rate limit by IP
        $limiter = $this->rateLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Rate limit exceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Authenticate
        $secret = $request->headers->get('X-Webhook-Secret');
        if (!$secret || !hash_equals($this->webhookSecret, $secret)) {
            $this->logger->warning('Agent webhook: invalid secret');
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Check payload size
        if ($request->headers->get('Content-Length', 0) > self::MAX_PAYLOAD_SIZE) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $data['userId'] ?? '';

        // Look up user by UUID
        if (!$userId || !Uuid::isValid($userId)) {
            return new JsonResponse(['error' => 'Invalid or missing userId'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            $this->logger->warning('Agent: unknown user', ['userId' => $userId]);
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->processor->process($user, $data);
    }
}
