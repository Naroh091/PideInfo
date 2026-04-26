<?php

declare(strict_types=1);

namespace App\Controller\OAuth2;

use App\Entity\DynamicClientMetadata;
use App\Repository\DynamicClientMetadataRepository;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Implements RFC 7591 Dynamic Client Registration so MCP clients (Claude.ai,
 * ChatGPT, Cursor, MCP Inspector) can self-register without operator action.
 *
 * Returns 201 with client_id (and client_secret for confidential clients);
 * unauthenticated and rate-limited.
 */
final class ClientRegistrationController
{
    private const ALLOWED_AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];
    private const ALLOWED_GRANT_TYPES = ['authorization_code', 'refresh_token'];
    private const SUPPORTED_SCOPES = ['mcp:read', 'mcp:write', 'mcp:documents', 'offline_access'];

    public function __construct(
        private readonly ClientManagerInterface $clientManager,
        private readonly DynamicClientMetadataRepository $metadataRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.oauth_register')]
        private readonly RateLimiterFactory $oauthRegisterLimiter,
    ) {
    }

    #[Route(
        path: '/oauth2/register',
        name: 'oauth2_register',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $limiter = $this->oauthRegisterLimiter->create($request->getClientIp() ?? 'anonymous');
        $reservation = $limiter->consume();
        if (!$reservation->isAccepted()) {
            throw new TooManyRequestsHttpException(
                (int) ceil($reservation->getRetryAfter()->getTimestamp() - time()),
                'Too many client registrations from this IP.'
            );
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('invalid_client_metadata', 'Request body must be a JSON object.');
        }

        $redirectUris = $payload['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || [] === $redirectUris) {
            return $this->error('invalid_redirect_uri', 'redirect_uris is required and must be a non-empty array.');
        }

        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !filter_var($uri, FILTER_VALIDATE_URL)) {
                return $this->error('invalid_redirect_uri', \sprintf('Invalid redirect_uri "%s".', is_string($uri) ? $uri : ''));
            }
            $scheme = parse_url($uri, PHP_URL_SCHEME);
            if ('https' !== $scheme && 'http' !== $scheme) {
                return $this->error('invalid_redirect_uri', 'Only http(s) redirect URIs are accepted.');
            }
        }

        $authMethod = $payload['token_endpoint_auth_method'] ?? 'none';
        if (!is_string($authMethod) || !in_array($authMethod, self::ALLOWED_AUTH_METHODS, true)) {
            return $this->error('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }

        $grantTypes = $payload['grant_types'] ?? ['authorization_code'];
        if (!is_array($grantTypes)) {
            return $this->error('invalid_client_metadata', 'grant_types must be an array.');
        }
        foreach ($grantTypes as $grant) {
            if (!is_string($grant) || !in_array($grant, self::ALLOWED_GRANT_TYPES, true)) {
                return $this->error('invalid_client_metadata', \sprintf('Unsupported grant_type "%s".', is_string($grant) ? $grant : ''));
            }
        }

        $requestedScopeString = $payload['scope'] ?? 'mcp:read';
        if (!is_string($requestedScopeString)) {
            return $this->error('invalid_client_metadata', 'scope must be a space-delimited string.');
        }
        $requestedScopes = array_filter(explode(' ', $requestedScopeString));
        foreach ($requestedScopes as $scope) {
            if (!in_array($scope, self::SUPPORTED_SCOPES, true)) {
                return $this->error('invalid_scope', \sprintf('Unsupported scope "%s".', $scope));
            }
        }

        $clientName = $payload['client_name'] ?? 'MCP Client';
        if (!is_string($clientName) || '' === trim($clientName)) {
            $clientName = 'MCP Client';
        }
        $clientName = mb_substr(trim($clientName), 0, 255);

        $logoUri = isset($payload['logo_uri']) && is_string($payload['logo_uri']) && filter_var($payload['logo_uri'], FILTER_VALIDATE_URL)
            ? $payload['logo_uri']
            : null;

        $clientUri = isset($payload['client_uri']) && is_string($payload['client_uri']) && filter_var($payload['client_uri'], FILTER_VALIDATE_URL)
            ? $payload['client_uri']
            : null;

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = 'none' === $authMethod ? null : bin2hex(random_bytes(32));

        $client = new Client($clientName, $clientId, $clientSecret);
        $client->setActive(true);
        $client->setRedirectUris(...array_map(static fn (string $uri) => new RedirectUri($uri), $redirectUris));
        $client->setGrants(...array_map(static fn (string $g) => new Grant($g), $grantTypes));
        $client->setScopes(...array_map(static fn (string $s) => new Scope($s), $requestedScopes ?: ['mcp:read']));

        $this->clientManager->save($client);

        $registrationToken = bin2hex(random_bytes(32));
        $metadata = new DynamicClientMetadata($clientId, $clientName);
        $metadata->setLogoUri($logoUri);
        $metadata->setClientUri($clientUri);
        $metadata->setTokenEndpointAuthMethod($authMethod);
        $metadata->setRegistrationAccessTokenHash(hash('sha256', $registrationToken));
        $this->metadataRepository->save($metadata);

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'client_name' => $clientName,
            'scope' => implode(' ', $requestedScopes ?: ['mcp:read']),
            'registration_access_token' => $registrationToken,
            'registration_client_uri' => $this->urlGenerator->generate(
                'oauth2_register',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ).'/'.$clientId,
        ];

        if (null !== $clientSecret) {
            $response['client_secret'] = $clientSecret;
            $response['client_secret_expires_at'] = 0;
        }

        if (null !== $logoUri) {
            $response['logo_uri'] = $logoUri;
        }
        if (null !== $clientUri) {
            $response['client_uri'] = $clientUri;
        }

        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    private function error(string $code, string $description): JsonResponse
    {
        return new JsonResponse([
            'error' => $code,
            'error_description' => $description,
        ], Response::HTTP_BAD_REQUEST);
    }
}
