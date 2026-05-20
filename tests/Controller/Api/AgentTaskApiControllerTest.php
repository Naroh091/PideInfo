<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AgentTask;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AgentTaskApiControllerTest extends WebTestCase
{
    private function ensureUser($em): User
    {
        $user = $em->getRepository(User::class)->findOneBy([]);
        if ($user === null) {
            $user = new User();
            $user->setEmail('agent-task-api-test+'.bin2hex(random_bytes(4)).'@example.com');
            $user->setPassword('x');
            $user->setFirstName('Test');
            $user->setLastName('User');
        }
        if (!$user->isActive()) {
            $user->setIsActive(true);
        }
        $em->persist($user);
        $em->flush();
        return $user;
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

    public function testClaimReturns409OnSecondCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->ensureUser($em);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        $this->authenticate($client, $user);

        $client->request('POST', '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/claim');
        self::assertResponseIsSuccessful();
        $first = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_CLAIMED, $first['status']);

        $client->request('POST', '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/claim');
        self::assertResponseStatusCodeSame(409);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }

    public function testCompleteSuccessTransitionsToDone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->ensureUser($em);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setStatus(AgentTask::STATUS_IN_PROGRESS);
        $em->persist($task);
        $em->flush();

        $this->authenticate($client, $user);
        $client->request(
            'POST',
            '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['success' => true, 'result' => ['pdf_path' => '/tmp/x.pdf']])
        );
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_DONE, $body['status']);
        self::assertSame('/tmp/x.pdf', $body['result']['pdf_path']);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }

    public function testCompleteUncertainSetsTaskUncertainAndFlagsRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->ensureUser($em);

        $request = $em->getRepository(\App\Entity\AccessRequest::class)->findOneBy([]);
        if ($request === null) {
            self::markTestSkipped('No AccessRequest fixture available in the test DB.');
        }
        $request->setMetadata(null);

        $task = new AgentTask($user, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        $task->setAccessRequest($request);
        $task->setStatus(AgentTask::STATUS_IN_PROGRESS);
        $em->persist($task);
        $em->flush();

        $this->authenticate($client, $user);
        $client->request(
            'POST',
            '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/complete',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'outcome' => 'uncertain',
                'error' => 'submission_uncertain:step3',
                'result' => ['markers' => ['idBorr' => '999001']],
            ])
        );
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_UNCERTAIN, $body['status']);

        $em->refresh($request);
        $flag = $request->getMetadataValue('submission_uncertain');
        self::assertIsArray($flag);
        self::assertSame(AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, $flag['channel']);
        $markers = $request->getMetadataValue('portal_markers');
        self::assertSame('999001', $markers[AgentTask::TYPE_SUBMIT_REQUEST_PORTAL]['idBorr']);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $request->setMetadata(null);
        $em->flush();
    }
}
