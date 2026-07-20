<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\EventListener\ClaimAnonymousDraftsOnLoginListener;
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
 * Intención de envío post-claim: si el visitante llegó a /redactar/** /enviar
 * y luego inicia sesión, aterriza directamente en su expediente reclamado.
 * Sin intención (o con borrador ajeno) el login sigue su redirección normal.
 */
final class ClaimAnonymousDraftsOnLoginListenerTest extends KernelTestCase
{
    private Session $session;

    /** Boots the kernel with a simulated anonymous browser session. */
    private function bootWithSession(): void
    {
        self::bootKernel();
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
    }

    private function makeDraft(string $flow): AccessRequest
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo intent de prueba ' . bin2hex(random_bytes(3)));
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Borrador con intención');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        if ($flow === 'complaint') {
            $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        }
        $draft->setMetadataValue('anonymous', ['flow' => $flow, 'turns' => 1]);
        $em->persist($draft);

        return $draft;
    }

    private function makeUser(): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = new User();
        $user->setEmail('intent-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('Intent');
        $em->persist($user);

        return $user;
    }

    private function loginEvent(User $user): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            new Request(),
            null,
            'main',
        );
    }

    public function testIntentRedirectsToClaimedRequest(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('request');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'request']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/solicitudes/' . $draft->getId(), $response->getTargetUrl());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la intención se consume');
    }

    public function testComplaintIntentRedirectsToComplaintRedactar(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('complaint');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'complaint']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/solicitudes/' . $draft->getId() . '/redactar', $response->getTargetUrl());
        self::assertStringContainsString('mode=complaint', $response->getTargetUrl());
    }

    public function testNoIntentLeavesResponseUntouched(): void
    {
        $this->bootWithSession();
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        self::assertNull($event->getResponse());
    }

    public function testIntentForUnclaimedDraftIsIgnored(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('request');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        // Intent present but the draft id is NOT in anon_draft_ids: the claim
        // never assigns it, so the ownership check must reject the redirect.
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'request']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        self::assertNull($event->getResponse());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la intención se consume igualmente');
    }
}
