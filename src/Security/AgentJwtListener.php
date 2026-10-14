<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Rejects agent JWT tokens (issued by AgentTokenController) whose `iat`
 * predates the user's `agentTokensInvalidatedAt` cut-off — i.e. the user
 * has revoked the agent connection from the panel.
 *
 * Lexik JWT itself is stateless; this gives us a per-user revocation point
 * without maintaining a JTI blacklist.
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
final class AgentJwtListener
{
    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        $type = $payload['type'] ?? null;
        // Agent and API v1 share the time-cutoff revocation model.
        if ($type !== 'agent' && $type !== 'api') {
            return;
        }

        $user = $event->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $invalidatedAt = $type === 'api' ? $user->getApiTokensInvalidatedAt() : $user->getAgentTokensInvalidatedAt();
        if (null === $invalidatedAt) {
            return;
        }

        $iat = $payload['iat'] ?? null;
        if (!is_int($iat) || $iat < $invalidatedAt->getTimestamp()) {
            throw new AuthenticationException($type === 'api' ? 'API token has been revoked.' : 'Agent token has been revoked.');
        }
    }
}
