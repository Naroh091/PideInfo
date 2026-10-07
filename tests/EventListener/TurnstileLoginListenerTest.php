<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\TurnstileLoginListener;
use App\Service\Security\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;

/**
 * Turnstile on login. The listener is wired onto EVERY firewall's dispatcher,
 * so what is covered here is both the rejection of an invalid token and the
 * gates that let an attempt through untouched: Turnstile not configured, and
 * passports that are not a username/password attempt (remember-me, the /api
 * JWT, the /mcp access tokens).
 */
final class TurnstileLoginListenerTest extends TestCase
{
    /** @param list<MockResponse> $responses */
    private function listener(string $secret, Request $request, array $responses = []): TurnstileLoginListener
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new TurnstileLoginListener(
            new TurnstileVerifier(new MockHttpClient($responses), new NullLogger(), $secret),
            $stack,
        );
    }

    private function loginRequest(?string $token): Request
    {
        return new Request(
            server: ['REMOTE_ADDR' => '203.0.113.7'],
            request: array_filter([
                '_username' => 'quien@sea.es',
                '_password' => 'hunter2',
                TurnstileLoginListener::TOKEN_FIELD => $token,
            ], static fn ($v) => $v !== null),
        );
    }

    private function passwordEvent(): CheckPassportEvent
    {
        $passport = new Passport(
            new UserBadge('quien@sea.es', static fn () => new User()),
            new PasswordCredentials('hunter2'),
        );

        return new CheckPassportEvent($this->createMock(AuthenticatorInterface::class), $passport);
    }

    private function siteVerify(bool $success): MockResponse
    {
        return new MockResponse(
            json_encode(['success' => $success, 'error-codes' => $success ? [] : ['invalid-input-response']]),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    public function testRejectsAnInvalidToken(): void
    {
        $listener = $this->listener('un-secreto', $this->loginRequest('token-falso'), [$this->siteVerify(false)]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No hemos podido verificar que no eres un robot.');

        $listener($this->passwordEvent());
    }

    public function testRejectsAMissingToken(): void
    {
        // With no token Cloudflare is not even called: a MockHttpClient with
        // no queued responses would blow up if it were.
        $listener = $this->listener('un-secreto', $this->loginRequest(null));

        $this->expectException(AuthenticationException::class);

        $listener($this->passwordEvent());
    }

    public function testLetsAValidTokenThrough(): void
    {
        $listener = $this->listener('un-secreto', $this->loginRequest('token-bueno'), [$this->siteVerify(true)]);

        $listener($this->passwordEvent());

        $this->expectNotToPerformAssertions();
    }

    public function testDoesNothingWhenTurnstileIsNotConfigured(): void
    {
        $listener = $this->listener('', $this->loginRequest(null));

        $listener($this->passwordEvent());

        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresPassportsWithoutPasswordCredentials(): void
    {
        // Remember-me / JWT / access token: no captcha token, and no reason
        // to have one. Configured secret, no token, still goes through.
        $listener = $this->listener('un-secreto', new Request());

        $event = new CheckPassportEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('quien@sea.es', static fn () => new User())),
        );

        $listener($event);

        $this->expectNotToPerformAssertions();
    }

    public function testFailsOpenWhenCloudflareIsUnreachable(): void
    {
        // A Cloudflare outage must not lock anybody out of their account.
        $listener = $this->listener('un-secreto', $this->loginRequest('token-bueno'), [
            new MockResponse('', ['error' => 'connection refused']),
        ]);

        $listener($this->passwordEvent());

        $this->expectNotToPerformAssertions();
    }
}
