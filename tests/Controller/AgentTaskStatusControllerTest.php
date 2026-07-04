<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AgentTask;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The browser modal (`agentPresent`) polls task status with the session cookie,
 * so status must be served by the `main` firewall — NOT `/api/` (stateless+JWT,
 * which answers 401 "JWT Token not found" to a cookie session).
 */
class AgentTaskStatusControllerTest extends WebTestCase
{
    private function ensureUser($em): User
    {
        $user = $em->getRepository(User::class)->findOneBy([]);
        if ($user === null) {
            $user = new User();
            $user->setEmail('agent-task-status-test+'.bin2hex(random_bytes(4)).'@example.com');
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

    public function testStatusReadableWithCookieSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->ensureUser($em);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setStatus(AgentTask::STATUS_IN_PROGRESS);
        $em->persist($task);
        $em->flush();

        // Cookie-based login — exactly how the browser modal is authenticated.
        $client->loginUser($user);
        $client->request('GET', '/panel/agent/tasks/' . $task->getId()->toRfc4122() . '/estado');

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_IN_PROGRESS, $body['status']);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }

    public function testStatusRejectsUnauthenticated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->ensureUser($em);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        // No login: the main firewall redirects to /login (302), it does NOT
        // return the JWT 401 that broke the modal.
        $client->request('GET', '/panel/agent/tasks/' . $task->getId()->toRfc4122() . '/estado');
        self::assertResponseStatusCodeSame(302);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }

    public function testStatusHidesOtherUsersTask(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $owner = $this->ensureUser($em);

        $other = new User();
        $other->setEmail('agent-task-status-other+'.bin2hex(random_bytes(4)).'@example.com');
        $other->setPassword('x');
        $other->setFirstName('Other');
        $other->setLastName('User');
        $other->setIsActive(true);
        $em->persist($other);
        $em->flush();

        $task = new AgentTask($owner, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        $client->loginUser($other);
        $client->request('GET', '/panel/agent/tasks/' . $task->getId()->toRfc4122() . '/estado');
        self::assertResponseStatusCodeSame(404);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->remove($em->find(User::class, $other->getId()));
        $em->flush();
    }
}
