<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AgentTaskRepositoryTest extends KernelTestCase
{
    public function testClaimAtomicallyReturnsTaskOnFirstCallAndNullOnSecond(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var AgentTaskRepository $repo */
        $repo = $em->getRepository(AgentTask::class);

        $user = $em->getRepository(User::class)->findOneBy([]);
        if ($user === null) {
            $user = new User();
            $user->setEmail('agent-task-test+'.bin2hex(random_bytes(4)).'@example.com');
            $user->setPassword('x');
            $user->setFirstName('Test');
            $user->setLastName('User');
            $em->persist($user);
            $em->flush();
        }

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        $first = $repo->claimAtomically($task->getId(), $user);
        self::assertNotNull($first);
        self::assertSame(AgentTask::STATUS_CLAIMED, $first->getStatus());

        $second = $repo->claimAtomically($task->getId(), $user);
        self::assertNull($second);

        $em->remove($first);
        $em->flush();
    }

    public function testFindNonTerminalForRequestIgnoresTerminalTasks(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var AgentTaskRepository $repo */
        $repo = $em->getRepository(AgentTask::class);

        $request = $em->getRepository(\App\Entity\AccessRequest::class)->findOneBy([]);
        if ($request === null) {
            self::markTestSkipped('No AccessRequest fixture available in the test DB.');
        }
        $user = $request->getUser();

        $failed = new AgentTask($user, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        $failed->setAccessRequest($request);
        $failed->setStatus(AgentTask::STATUS_FAILED);
        $em->persist($failed);
        $em->flush();

        self::assertNull(
            $repo->findNonTerminalForRequest($request, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL),
            'una tarea failed no cuenta como activa'
        );

        $active = new AgentTask($user, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        $active->setAccessRequest($request);
        $active->setStatus(AgentTask::STATUS_CLAIMED);
        $em->persist($active);
        $em->flush();

        $found = $repo->findNonTerminalForRequest($request, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        self::assertNotNull($found);
        self::assertSame($active->getId()->toRfc4122(), $found->getId()->toRfc4122());

        $em->remove($active);
        $em->remove($failed);
        $em->flush();
    }
}
