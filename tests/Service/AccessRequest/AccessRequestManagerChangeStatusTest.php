<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Service\AccessRequest\AccessRequestManager;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reglas de coherencia status ↔ resolutionResult/resolvedAt de changeStatus():
 * los estados con decisión expresa (incluidos parcial e inadmitida) infieren
 * su resolutionResult y fijan resolvedAt; reabrir una solicitud limpia ambos;
 * la prórroga que levanta un silencio limpia el resultado inferido "silence".
 */
final class AccessRequestManagerChangeStatusTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    private function manager(): AccessRequestManager
    {
        self::bootKernel();

        return static::getContainer()->get(AccessRequestManager::class);
    }

    private function request(string $status = AccessRequest::STATUS_PROCESSING): AccessRequest
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setEmail('change-status-test+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $em->persist($user);

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Prueba');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $request = new AccessRequest();
        $request->setUser($user);
        $request->setPublicBody($body);
        $request->setApplicableLaw($law);
        $request->setTitle('Solicitud de prueba changeStatus');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-2 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));
        $request->setStatus($status);
        $em->persist($request);
        $em->flush();

        $this->trackFixtureRequest($request->getId()->toRfc4122());
        $this->trackFixtureUser($user->getId()->toRfc4122());
        $this->trackFixtureBody($body->getId()->toRfc4122());
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        return $request;
    }

    public function testPartiallyGrantedIsAcceptedAndInfersResolution(): void
    {
        $manager = $this->manager();
        $request = $this->request();

        $ok = $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_PARTIALLY_GRANTED);

        self::assertTrue($ok);
        self::assertSame(AccessRequest::STATUS_PARTIALLY_GRANTED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_PARTIALLY_GRANTED, $request->getResolutionResult());
        self::assertNotNull($request->getResolvedAt());
    }

    public function testInadmittedIsAcceptedAndInfersResolution(): void
    {
        $manager = $this->manager();
        $request = $this->request();

        $ok = $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_INADMITTED);

        self::assertTrue($ok);
        self::assertSame(AccessRequest::STATUS_INADMITTED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_INADMITTED, $request->getResolutionResult());
        self::assertNotNull($request->getResolvedAt());
    }

    public function testPartiallyGrantedClearsThirdPartySuspension(): void
    {
        $manager = $this->manager();
        $request = $this->request();
        $request->setDeadlineSuspendedAt(new \DateTimeImmutable('-5 days'));
        $request->setSuspendedDaysRemaining(7);
        $request->setThirdPartyStatus(AccessRequest::THIRD_PARTY_PENDING);

        $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_PARTIALLY_GRANTED);

        self::assertNull($request->getDeadlineSuspendedAt());
        self::assertNull($request->getSuspendedDaysRemaining());
        self::assertSame(AccessRequest::THIRD_PARTY_RECEIVED, $request->getThirdPartyStatus());
    }

    public function testReopeningClearsResolutionResultAndResolvedAt(): void
    {
        $manager = $this->manager();
        $request = $this->request(AccessRequest::STATUS_DENIED);
        $request->setResolutionResult(AccessRequest::RESULT_DENIED);
        $request->setResolvedAt(new \DateTimeImmutable('-2 days'));

        $ok = $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_PROCESSING);

        self::assertTrue($ok);
        self::assertSame(AccessRequest::STATUS_PROCESSING, $request->getStatus());
        self::assertNull($request->getResolutionResult());
        self::assertNull($request->getResolvedAt());
    }

    public function testCompletionAfterPartialGrantKeepsPartialResult(): void
    {
        $manager = $this->manager();
        $request = $this->request(AccessRequest::STATUS_PARTIALLY_GRANTED);
        $request->setResolutionResult(AccessRequest::RESULT_PARTIALLY_GRANTED);
        $request->setResolvedAt(new \DateTimeImmutable('-2 days'));

        $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_GRANTED_COMPLETED);

        self::assertSame(AccessRequest::STATUS_GRANTED_COMPLETED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_PARTIALLY_GRANTED, $request->getResolutionResult());
    }

    public function testDelayedInfersSilenceWithoutResolvedAt(): void
    {
        $manager = $this->manager();
        $request = $this->request(AccessRequest::STATUS_SENT);

        $manager->changeStatus($request, StatusHistory::TYPE_STATUS, AccessRequest::STATUS_DELAYED);

        self::assertSame(AccessRequest::STATUS_DELAYED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_SILENCE, $request->getResolutionResult());
        self::assertNull($request->getResolvedAt());
    }

    public function testExtensionAfterSilenceClearsInferredSilenceResult(): void
    {
        $manager = $this->manager();
        $request = $this->request(AccessRequest::STATUS_DELAYED);
        $request->setResolutionResult(AccessRequest::RESULT_SILENCE);
        $request->setResolvedAt(new \DateTimeImmutable('-2 days'));

        $ok = $manager->extendDeadlineByLaw($request);

        self::assertTrue($ok);
        self::assertSame(AccessRequest::STATUS_PROCESSING, $request->getStatus());
        self::assertNull($request->getResolutionResult());
        self::assertNull($request->getResolvedAt());
    }

    public function testExtensionAfterSilenceKeepsExplicitDecision(): void
    {
        // Salvaguarda: si por algún camino el resultado ya era una decisión
        // expresa, la prórroga no debe borrarla — solo se limpia el silencio.
        $manager = $this->manager();
        $request = $this->request(AccessRequest::STATUS_DELAYED);
        $request->setResolutionResult(AccessRequest::RESULT_PARTIALLY_GRANTED);

        $manager->extendDeadlineByLaw($request);

        self::assertSame(AccessRequest::RESULT_PARTIALLY_GRANTED, $request->getResolutionResult());
    }
}
