<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Reminder;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Add a personal reminder for a specific access request (or general).
 */
#[McpTool(
    name: 'add_reminder',
    description: 'Crea un recordatorio personal para una fecha (opcionalmente vinculado a una solicitud).',
)]
final class AddReminderTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $remindAt  ISO 8601 date when the reminder fires (YYYY-MM-DD).
     * @param string|null $note      Optional message; shown next to the reminder in the UI.
     * @param string|null $requestId Optional access-request UUID to attach the reminder to.
     *
     * @return array{id: string, remindAt: string, note: ?string, requestId: ?string}
     */
    public function __invoke(string $remindAt, ?string $note = null, ?string $requestId = null): array
    {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        try {
            $remindAtDate = new \DateTimeImmutable($remindAt);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('remindAt must be a valid ISO 8601 date.');
        }
        if ($remindAtDate < new \DateTimeImmutable('today')) {
            throw new InvalidArgumentException('remindAt must be today or in the future.');
        }

        $accessRequest = null;
        if (null !== $requestId) {
            if (!Uuid::isValid($requestId)) {
                throw new InvalidArgumentException('Invalid request id.');
            }
            $accessRequest = $this->accessRequestRepository->find(Uuid::fromString($requestId));
            if (null === $accessRequest || $accessRequest->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
                throw new AccessDeniedException('Request not found or not yours.');
            }
        }

        $reminder = new Reminder();
        $reminder->setUser($user);
        $reminder->setAccessRequest($accessRequest);
        $reminder->setRemindAt($remindAtDate);
        $reminder->setNote($note);

        $this->em->persist($reminder);
        $this->em->flush();

        return [
            'id' => $reminder->getId()->toRfc4122(),
            'remindAt' => $reminder->getRemindAt()->format(\DATE_ATOM),
            'note' => $reminder->getNote(),
            'requestId' => $accessRequest?->getId()->toRfc4122(),
        ];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
