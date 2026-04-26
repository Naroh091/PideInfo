<?php

declare(strict_types=1);

namespace App\Security\OAuth2;

use App\Repository\UserRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Uid\Uuid;

/**
 * Validates incoming bearer tokens against league/oauth2-server's ResourceServer
 * and populates OAuthTokenContext with scopes/client info for downstream tools.
 */
final class OAuth2TokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly UserRepository $userRepository,
        private readonly OAuthTokenContext $tokenContext,
        private readonly RequestStack $requestStack,
        private readonly Psr17Factory $psr17Factory = new Psr17Factory(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new BadCredentialsException('No active request to validate the OAuth2 token.');
        }

        $serverRequest = $this->psr17Factory
            ->createServerRequest($request->getMethod(), $request->getUri())
            ->withHeader('Authorization', 'Bearer '.$accessToken);

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($serverRequest);
        } catch (OAuthServerException $e) {
            $this->logger->info('OAuth2 token rejected: {msg}', ['msg' => $e->getMessage()]);
            throw new BadCredentialsException('Invalid OAuth2 access token.', 0, $e);
        }

        $userIdentifier = (string) $validated->getAttribute('oauth_user_id');
        $clientId = (string) $validated->getAttribute('oauth_client_id');
        $tokenId = (string) $validated->getAttribute('oauth_access_token_id');
        $scopes = (array) ($validated->getAttribute('oauth_scopes') ?? []);
        $scopes = array_map('strval', $scopes);

        if ('' === $userIdentifier) {
            throw new BadCredentialsException('OAuth2 token has no associated user (likely a client credentials grant, not allowed for MCP).');
        }

        if (!Uuid::isValid($userIdentifier)) {
            throw new BadCredentialsException('OAuth2 token user identifier is not a valid UUID.');
        }

        $this->tokenContext->set($userIdentifier, $clientId, $tokenId, $scopes);

        $userRepository = $this->userRepository;

        return new UserBadge(
            $userIdentifier,
            static function (string $identifier) use ($userRepository) {
                $user = $userRepository->find(Uuid::fromString($identifier));
                if (null === $user) {
                    throw new BadCredentialsException('User from OAuth2 token no longer exists.');
                }

                return $user;
            }
        );
    }
}
