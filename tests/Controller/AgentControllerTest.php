<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AgentTask;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /perfil/agente lista el historial completo de AgentTask del usuario. El
 * aislamiento por usuario se hace en la query (AgentTaskRepository), no con un
 * Voter: si la query dejara de filtrar, un usuario vería las tareas de otro.
 */
class AgentControllerTest extends WebTestCase
{
    private function makeUser($em, string $prefix): User
    {
        $user = new User();
        $user->setEmail($prefix.'+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testPageRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->makeUser($em, 'agente-page');

        $client->loginUser($user);
        $client->request('GET', '/perfil/agente');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Agente');
        self::assertStringContainsString('Aún no hay tareas del agente', $client->getResponse()->getContent());

        $em->remove($em->find(User::class, $user->getId()));
        $em->flush();
    }

    public function testPageRedirectsUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/agente');

        self::assertResponseStatusCodeSame(302);
    }

    public function testPageHidesOtherUsersTasks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $owner = $this->makeUser($em, 'agente-owner');
        $other = $this->makeUser($em, 'agente-other');

        $task = new AgentTask($owner, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        $task->setStatus(AgentTask::STATUS_FAILED);
        $task->setErrorMessage('step2_validation: campo inválido');
        $em->persist($task);
        $em->flush();

        $client->loginUser($other);
        $client->request('GET', '/perfil/agente');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('step2_validation', $client->getResponse()->getContent());
        self::assertStringContainsString('Aún no hay tareas del agente', $client->getResponse()->getContent());

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->remove($em->find(User::class, $other->getId()));
        $em->remove($em->find(User::class, $owner->getId()));
        $em->flush();
    }

    public function testStatusFilterNarrowsTheList(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->makeUser($em, 'agente-filter');

        $done = new AgentTask($user, AgentTask::TYPE_SUBMIT_REQUEST_REG);
        $done->setStatus(AgentTask::STATUS_DONE);
        $done->setResult(['externalId' => 'REGAGE-DONE-1']);
        $em->persist($done);

        $failed = new AgentTask($user, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);
        $failed->setStatus(AgentTask::STATUS_FAILED);
        $failed->setErrorMessage('step2_portal_timeout');
        $em->persist($failed);
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/perfil/agente');
        $all = $client->getResponse()->getContent();
        self::assertStringContainsString('REGAGE-DONE-1', $all);
        self::assertStringContainsString('step2_portal_timeout', $all);

        $client->request('GET', '/perfil/agente?estado=failed');
        $onlyFailed = $client->getResponse()->getContent();
        self::assertStringNotContainsString('REGAGE-DONE-1', $onlyFailed);
        self::assertStringContainsString('step2_portal_timeout', $onlyFailed);

        // Un valor de filtro desconocido no debe filtrar ni reventar.
        $client->request('GET', '/perfil/agente?estado=marte');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('REGAGE-DONE-1', $client->getResponse()->getContent());

        $em->remove($em->find(AgentTask::class, $done->getId()));
        $em->remove($em->find(AgentTask::class, $failed->getId()));
        $em->remove($em->find(User::class, $user->getId()));
        $em->flush();
    }
}
