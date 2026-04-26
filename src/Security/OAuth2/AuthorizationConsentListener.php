<?php

declare(strict_types=1);

namespace App\Security\OAuth2;

use App\Repository\DynamicClientMetadataRepository;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Intercepts league/oauth2-server-bundle's authorization flow to render a
 * consent screen on the first hit and to resolve approve/deny on submission.
 *
 * Consent posts back to the same /oauth2/authorize URL preserving all the
 * original query-string parameters; the event listener detects the
 * `_consent` field on POST and resolves the authorization accordingly.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AuthorizationConsentListener
{
    private const CSRF_TOKEN_ID = 'oauth2_consent';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
        private readonly DynamicClientMetadataRepository $dynamicClientRepository,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return;
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
                $event->setResponse(new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST));

                return;
            }

            $decision = $request->request->get('_consent');
            if ('approve' === $decision) {
                $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);

                return;
            }

            if ('deny' === $decision) {
                $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

                return;
            }
        }

        $client = $event->getClient();
        $metadata = $this->dynamicClientRepository->find($client->getIdentifier());

        $html = $this->twig->render('oauth2/consent.html.twig', [
            'client_name' => $metadata?->getClientName() ?? $client->getName(),
            'client_logo_uri' => $metadata?->getLogoUri(),
            'client_uri' => $metadata?->getClientUri(),
            'is_dynamic' => null !== $metadata && $metadata->isDynamic(),
            'scopes' => array_map('strval', $event->getScopes()),
            'user' => $event->getUser(),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'form_action' => $request->getRequestUri(),
        ]);

        $event->setResponse(new Response($html));
    }
}
