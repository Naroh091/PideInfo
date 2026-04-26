<?php

declare(strict_types=1);

namespace App\Controller\OAuth2;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Publishes RFC 8414 (OAuth Authorization Server Metadata),
 * RFC 9728 (OAuth Protected Resource Metadata) and a JWKS document
 * so MCP clients can auto-discover this server.
 */
final class DiscoveryController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $publicKeyPath,
        private readonly string $mcpEndpointPath = '/mcp',
    ) {
    }

    #[Route(
        path: '/.well-known/oauth-authorization-server',
        name: 'oauth2_well_known_authorization_server',
        methods: ['GET'],
    )]
    public function authorizationServerMetadata(): JsonResponse
    {
        $issuer = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        // Trim trailing /login to keep just the host root for the issuer.
        $issuer = rtrim(str_replace('/login', '', $issuer), '/');

        return new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => $this->urlGenerator->generate('oauth2_authorize', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'token_endpoint' => $this->urlGenerator->generate('oauth2_token', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'registration_endpoint' => $this->urlGenerator->generate('oauth2_register', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'jwks_uri' => $this->urlGenerator->generate('oauth2_jwks', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:documents', 'offline_access'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
            'service_documentation' => $issuer.'/docs/mcp.md',
        ], headers: ['Cache-Control' => 'public, max-age=3600']);
    }

    #[Route(
        path: '/.well-known/oauth-protected-resource',
        name: 'oauth2_well_known_protected_resource',
        methods: ['GET'],
    )]
    public function protectedResourceMetadata(): JsonResponse
    {
        $issuer = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $issuer = rtrim(str_replace('/login', '', $issuer), '/');

        return new JsonResponse([
            'resource' => $issuer.$this->mcpEndpointPath,
            'authorization_servers' => [$issuer],
            'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:documents'],
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $issuer.'/docs/mcp.md',
        ], headers: ['Cache-Control' => 'public, max-age=3600']);
    }

    #[Route(
        path: '/.well-known/jwks.json',
        name: 'oauth2_jwks',
        methods: ['GET'],
    )]
    public function jwks(): JsonResponse
    {
        $publicKey = @file_get_contents($this->publicKeyPath);
        if (false === $publicKey) {
            return new JsonResponse(['keys' => []]);
        }

        $resource = openssl_pkey_get_public($publicKey);
        if (false === $resource) {
            return new JsonResponse(['keys' => []]);
        }

        $details = openssl_pkey_get_details($resource);
        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            return new JsonResponse(['keys' => []]);
        }

        $kid = substr(hash('sha256', $publicKey), 0, 16);

        return new JsonResponse([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => self::base64UrlEncode($details['rsa']['n']),
                'e' => self::base64UrlEncode($details['rsa']['e']),
            ]],
        ], headers: ['Cache-Control' => 'public, max-age=3600']);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
