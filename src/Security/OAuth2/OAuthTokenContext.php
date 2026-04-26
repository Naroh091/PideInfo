<?php

declare(strict_types=1);

namespace App\Security\OAuth2;

use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Request-scoped context populated by OAuth2TokenHandler with information
 * extracted from the validated bearer token. MCP tools/resources read this
 * to enforce per-scope authorization and to record the issuing client in
 * audit trails.
 */
final class OAuthTokenContext
{
    private ?string $userId = null;
    private ?string $clientId = null;
    private ?string $accessTokenId = null;
    /** @var list<string> */
    private array $scopes = [];

    /**
     * @param list<string> $scopes
     */
    public function set(string $userId, string $clientId, string $accessTokenId, array $scopes): void
    {
        $this->userId = $userId;
        $this->clientId = $clientId;
        $this->accessTokenId = $accessTokenId;
        $this->scopes = array_values(array_unique($scopes));
    }

    public function getUserId(): string
    {
        if (null === $this->userId) {
            throw new UnexpectedValueException('OAuth token context is empty; this should only be used inside the MCP firewall.');
        }

        return $this->userId;
    }

    public function getClientId(): string
    {
        if (null === $this->clientId) {
            throw new UnexpectedValueException('OAuth token context is empty; this should only be used inside the MCP firewall.');
        }

        return $this->clientId;
    }

    public function getAccessTokenId(): ?string
    {
        return $this->accessTokenId;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function requireScope(string $scope): void
    {
        if (!$this->hasScope($scope)) {
            throw new AccessDeniedException(\sprintf('Missing required OAuth2 scope "%s".', $scope));
        }
    }
}
