<?php

declare(strict_types=1);

namespace App\Security\OAuth2;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Entry point for the `mcp` firewall. Emits a 401 with a WWW-Authenticate
 * header so MCP clients (Claude.ai, ChatGPT, MCP Inspector) can autodiscover
 * the authorization server through the protected-resource metadata document
 * (RFC 9728 + draft-ietf-oauth-resource-metadata).
 */
final class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $resourceMetadata = $this->urlGenerator->generate(
            'oauth2_well_known_protected_resource',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $error = $authException?->getMessage() ?? 'Authentication required.';

        $body = [
            'error' => 'unauthorized',
            'error_description' => $error,
            'jsonrpc' => '2.0',
            'id' => null,
        ];

        return new JsonResponse(
            data: $body,
            status: Response::HTTP_UNAUTHORIZED,
            headers: [
                'WWW-Authenticate' => \sprintf(
                    'Bearer realm="mcp", resource_metadata="%s"',
                    $resourceMetadata,
                ),
            ],
        );
    }
}
