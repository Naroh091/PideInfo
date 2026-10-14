<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Batch quotas and explicit confirmation (spec §4/§5), enforced server-side:
 * - Individual (1 recipient): starting with 2 → 422 with no partial drafts.
 * - Dispatching without confirmReview → 422 with no AgentTask.
 * - With confirmation → 200 with 1 task.
 */
final class BatchQuotaDispatchTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testInitiateRejectsOverQuotaWithoutPartialDrafts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'quota-individual@example.com');
        [$bodyA, $bodyB] = $this->makePortalBodies($em);
        $em->flush();

        $client->loginUser($user);
        $client->request(
            'POST',
            '/solicitudes/nueva/realizar/iniciar',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['targets' => [
                ['publicBodyId' => $bodyA->getId()->toRfc4122()],
                ['publicBodyId' => $bodyB->getId()->toRfc4122()],
            ]]),
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('batch_quota_exceeded', $data['error']);
        self::assertSame(1, $data['max']);

        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);
        self::assertSame(0, $repo->countByUser($user), 'ningún borrador parcial');
    }

    public function testDispatchRequiresExplicitConfirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'quota-confirm@example.com');
        [$body] = $this->makePortalBodies($em);
        $em->flush();

        $client->loginUser($user);
        [$batchId, $draftId] = $this->initiate($client, [$body->getId()->toRfc4122()]);

        $client->request(
            'POST',
            '/solicitudes/nueva/realizar/enviar/'.$batchId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            json_encode(['drafts' => [$draftId => [
                'title' => 'Contratos menores 2025',
                'description' => 'Solicito la relación de contratos menores del ejercicio.',
            ]]]),
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('confirmation_required', $data['error']);

        $em->clear();
        $draft = $em->getRepository(AccessRequest::class)->find($draftId);
        $tasks = $em->getRepository(\App\Entity\AgentTask::class)->findBy(['accessRequest' => $draft]);
        self::assertSame([], $tasks, 'sin confirmación no se crea ninguna tarea');
    }

    public function testDispatchWithConfirmationCreatesTask(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'quota-ok@example.com');
        [$body] = $this->makePortalBodies($em);
        $em->flush();

        $client->loginUser($user);
        [$batchId, $draftId] = $this->initiate($client, [$body->getId()->toRfc4122()]);

        $client->request(
            'POST',
            '/solicitudes/nueva/realizar/enviar/'.$batchId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            json_encode([
                'confirmReview' => true,
                'drafts' => [$draftId => [
                    'title' => 'Contratos menores 2025',
                    'description' => 'Solicito la relación de contratos menores del ejercicio.',
                ]],
            ]),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $data['dispatched']);
        self::assertTrue($data['complete']);
        self::assertCount(1, $data['tasks']);
    }

    /** @param list<string> $bodyIds @return array{string, string} [batchId, firstDraftId] */
    private function initiate(KernelBrowser $client, array $bodyIds): array
    {
        $client->request(
            'POST',
            '/solicitudes/nueva/realizar/iniciar',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['targets' => array_map(
                static fn (string $id) => ['publicBodyId' => $id],
                $bodyIds,
            )]),
        );
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $firstUrl = (string) $data['firstDraftUrl'];
        preg_match('~/redactar/([0-9a-fA-F-]{36})~', $firstUrl, $m);

        $em = static::getContainer()->get('doctrine')->getManager();
        $draft = $em->getRepository(AccessRequest::class)->find($m[1]);
        $this->trackFixtureRequest($draft->getId()->toRfc4122());

        return [$data['batchId'], $m[1]];
    }

    private function makeUser($em, string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }

    /** @return list<PublicBody> */
    private function makePortalBodies($em): array
    {
        $law = (new ApplicableLaw())
            ->setName('Ley 19/2013 de transparencia')
            ->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        // ApplicableLawResolver::resolveFor picks a law per body: it needs an
        // applicable law for the body. Simplified with generic AGE bodies.
        $bodies = [];
        for ($i = 0; $i < 2; $i++) {
            $body = (new PublicBody())
                ->setName('Ministerio de cupo '.$i.' '.bin2hex(random_bytes(3)))
                ->setTransparencyPortalAmbId(100000 + $i);
            $em->persist($body);
            $this->trackFixtureBody($body->getId()->toRfc4122());
            $bodies[] = $body;
        }

        return $bodies;
    }
}
