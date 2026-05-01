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
}
