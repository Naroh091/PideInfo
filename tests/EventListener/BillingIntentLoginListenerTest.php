<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\BillingIntentLoginListener;
use App\Service\Billing\PlanIntentSessionStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Onboarding with a plan intent: whoever picked a plan before
 * registering lands on the upgrade screen at login.
 */
final class BillingIntentLoginListenerTest extends KernelTestCase
{
    public function testIntentRedirectsToUpgrade(): void
    {
        self::bootKernel();
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);

        $store = new PlanIntentSessionStore($stack);
        $store->remember('team', 'month');

        $user = (new User())
            ->setEmail('intent-billing@example.com')
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User');
        $event = new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            new Request(),
            null,
            'main',
        );

        static::getContainer()->get(BillingIntentLoginListener::class)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/facturacion/upgrade', $response->getTargetUrl());
        self::assertStringContainsString('plan=team', $response->getTargetUrl());
        self::assertFalse($session->has('billing_plan_intent'), 'la intención se consume');
    }

    public function testWithoutIntentLeavesResponseUntouched(): void
    {
        self::bootKernel();
        $user = (new User())
            ->setEmail('nointent-billing@example.com')
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User');
        $event = new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            new Request(),
            null,
            'main',
        );

        static::getContainer()->get(BillingIntentLoginListener::class)($event);

        self::assertNull($event->getResponse());
    }
}
