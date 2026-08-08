<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Message\UpdateExpiredRequestsMessage;
use App\MessageHandler\UpdateExpiredRequestsHandler;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El silencio automático debe pasar por el punto canónico (changeStatus):
 * además de status=delayed, la solicitud queda con resolutionResult=silence
 * y con su fila de StatusHistory — igual que un silencio marcado a mano.
 *
 * OJO: el handler procesa TODAS las solicitudes vencidas de la BD, no solo el
 * fixture. Contra el corpus vivo (TEST_DB_SUFFIX=) esto hace de facto el
 * trabajo del cron nocturno: cualquier solicitud real con plazo vencido en
 * sent/processing pasará a silencio (con su notificación). Los borradores
 * (pending) quedan fuera: no tienen plazo hasta que se envíen.
 */
final class UpdateExpiredRequestsHandlerTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    public function testExpiredRequestBecomesDelayedWithSilenceResult(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setEmail('expired-test+'.bin2hex(random_bytes(4)).'@example.com');
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
        $request->setTitle('Solicitud vencida de prueba');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-2 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));
        $request->setStatus(AccessRequest::STATUS_SENT);
        $em->persist($request);
        $em->flush();

        $this->trackFixtureRequest($request->getId()->toRfc4122());
        $this->trackFixtureUser($user->getId()->toRfc4122());
        $this->trackFixtureBody($body->getId()->toRfc4122());
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $handler = static::getContainer()->get(UpdateExpiredRequestsHandler::class);
        $handler(new UpdateExpiredRequestsMessage());

        $em->refresh($request);
        self::assertSame(AccessRequest::STATUS_DELAYED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_SILENCE, $request->getResolutionResult());
        self::assertNull($request->getResolvedAt());

        $historyRows = $em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $request]);
        $delayedRows = array_filter(
            $historyRows,
            static fn (StatusHistory $h) => $h->getToStatus() === AccessRequest::STATUS_DELAYED,
        );
        self::assertCount(1, $delayedRows);
    }

    /**
     * Los borradores anónimos (/redactar, user null) quedan fuera del barrido:
     * pasarlos a "delayed" rompería su UI de redacción y nadie recibe el aviso.
     */
    public function testAnonymousDraftIsNotExpired(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Prueba Anónima');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $request = new AccessRequest();
        $request->setPublicBody($body);
        $request->setApplicableLaw($law);
        $request->setTitle('Borrador anónimo vencido');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-2 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));
        $request->setStatus(AccessRequest::STATUS_PENDING);
        $em->persist($request);
        $em->flush();

        $this->trackFixtureRequest($request->getId()->toRfc4122());
        $this->trackFixtureBody($body->getId()->toRfc4122());
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $handler = static::getContainer()->get(UpdateExpiredRequestsHandler::class);
        $handler(new UpdateExpiredRequestsMessage());

        $em->refresh($request);
        self::assertSame(AccessRequest::STATUS_PENDING, $request->getStatus());
        self::assertNull($request->getResolutionResult());
    }
}
