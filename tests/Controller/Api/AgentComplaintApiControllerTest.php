<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\DeadlineHistory;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La reclamación presentada por el agente debe quedar con su plazo de
 * resolución (filedAt + 3 meses) y la fila de DeadlineHistory, igual que las
 * presentadas por cambio manual de estado o detectadas en documentos — si no,
 * nunca aparece en los avisos de plazos.
 */
class AgentComplaintApiControllerTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    private function makeUser($em): User
    {
        $user = new User();
        $user->setEmail('agent-complaint-api-test+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }

    private function makeRequest($em, User $user): AccessRequest
    {
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
        $request->setTitle('Solicitud de prueba agente-reclamación');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-3 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-2 months'));
        $request->setStatus(AccessRequest::STATUS_DELAYED);
        $em->persist($request);
        $em->flush();

        $this->trackFixtureRequest($request->getId()->toRfc4122());
        $this->trackFixtureBody($body->getId()->toRfc4122());
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        return $request;
    }

    private function authenticate(KernelBrowser $client, User $user): void
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $token = $jwtManager->createFromPayload($user, [
            'type' => 'agent',
            'email' => $user->getEmail(),
        ]);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    }

    public function testFiledSetsComplaintResolutionDeadlineAndHistory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->makeUser($em);
        $request = $this->makeRequest($em, $user);

        $this->authenticate($client, $user);
        $client->request(
            'POST',
            '/api/agent/complaints/filed',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'access_request_id' => $request->getId()->toRfc4122(),
                'registry_no' => 'REG-2026-000123',
                'filed_at' => '2026-07-01',
            ])
        );
        self::assertResponseIsSuccessful();

        $em->refresh($request);
        $complaint = $request->getComplaint();
        self::assertNotNull($complaint);
        self::assertNotNull($complaint->getDeadlineAt());
        self::assertSame('2026-10-01', $complaint->getDeadlineAt()->format('Y-m-d'));

        $complaintDeadlines = $em->getRepository(DeadlineHistory::class)->findBy([
            'accessRequest' => $request,
            'deadlineType' => DeadlineHistory::TYPE_COMPLAINT,
        ]);
        self::assertCount(1, $complaintDeadlines);
    }

    public function testFiledDoesNotOverwriteExistingDeadline(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->makeUser($em);
        $request = $this->makeRequest($em, $user);

        $this->authenticate($client, $user);

        $payload = [
            'access_request_id' => $request->getId()->toRfc4122(),
            'registry_no' => 'REG-2026-000124',
            'filed_at' => '2026-07-01',
        ];
        $client->request('POST', '/api/agent/complaints/filed', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        self::assertResponseIsSuccessful();

        // Segundo aviso del agente (reintento) con otra fecha: el plazo ya
        // fijado no debe moverse.
        $payload['filed_at'] = '2026-07-10';
        $client->request('POST', '/api/agent/complaints/filed', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        self::assertResponseIsSuccessful();

        $em->clear();
        $reloaded = $em->find(AccessRequest::class, $request->getId());
        self::assertSame('2026-10-01', $reloaded->getComplaint()->getDeadlineAt()->format('Y-m-d'));
    }
}
