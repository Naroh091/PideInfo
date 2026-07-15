<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Security\Voter\AccessRequestVoter;
use App\Service\Anonymous\AnonymousDraftSessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Rama anónima del voter: un borrador sin dueño (flujo /redactar) solo es
 * visible/editable desde la sesión que lo creó; nunca borrable; los admins
 * conservan acceso por el flujo normal y los extraños quedan fuera.
 */
final class AccessRequestVoterTest extends TestCase
{
    private Session $session;
    private AccessRequestVoter $voter;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $this->voter = new AccessRequestVoter(new AnonymousDraftSessionStore($requestStack));
    }

    private function ownerlessRequest(): AccessRequest
    {
        return new AccessRequest(); // user null by construction
    }

    public function testSessionGrantsViewAndEditOnOwnDraft(): void
    {
        $ar = $this->ownerlessRequest();
        $this->session->set('anon_draft_ids', [$ar->getId()->toRfc4122()]);
        $token = new NullToken();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $ar, ['view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $ar, ['edit']));
    }

    public function testDeleteIsNeverGrantedAnonymously(): void
    {
        $ar = $this->ownerlessRequest();
        $this->session->set('anon_draft_ids', [$ar->getId()->toRfc4122()]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), $ar, ['delete']));
    }

    public function testForeignSessionIsDenied(): void
    {
        $ar = $this->ownerlessRequest();
        // session does NOT contain the draft id

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), $ar, ['view']));
    }

    public function testAuthenticatedNonAdminIsDeniedOnOwnerlessDraft(): void
    {
        $ar = $this->ownerlessRequest();
        $user = new User();
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $ar, ['view']));
    }

    public function testAdminKeepsAccessToOwnerlessDraft(): void
    {
        $ar = $this->ownerlessRequest();
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $ar, ['view']));
    }

    public function testOwnedRequestIgnoresSessionStore(): void
    {
        $owner = new User();
        $ar = new AccessRequest();
        $ar->setUser($owner);
        // Even if the id leaked into an anonymous session, ownership rules apply.
        $this->session->set('anon_draft_ids', [$ar->getId()->toRfc4122()]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), $ar, ['view']));
    }
}
