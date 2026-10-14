<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST with a valid CSRF token: `loginUser()` opens no session, so the real
 * session is created (or reused), the token is generated against it and the
 * matching cookie is sent. Without this, every CSRF POST dies with
 * SessionNotFoundException or 403.
 */
trait MakesSessionCsrfPosts
{
    private function postWithSessionToken(
        KernelBrowser $client,
        string $uri,
        string $tokenId,
        array $params = [],
    ): void {
        $container = static::getContainer();
        $session = $container->get('session.factory')->createSession();

        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        $params['_token'] = $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        $client->request('POST', $uri, $params);
    }
}
