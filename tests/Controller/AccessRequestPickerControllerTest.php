<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccessRequestPickerControllerTest extends WebTestCase
{
    public function testFacetasRejectsUnknownNivel(): void
    {
        $client = static::createClient();
        $this->logInFirstUser($client);

        $client->request('GET', '/solicitudes/nueva/realizar/facetas.json?nivel=inexistente');
        self::assertResponseStatusCodeSame(400);
    }

    public function testFacetasReturnsComunidadesForLocal(): void
    {
        $client = static::createClient();
        $this->logInFirstUser($client);

        $client->request('GET', '/solicitudes/nueva/realizar/facetas.json?nivel=local');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('comunidad', $data['facetType']);
        self::assertIsArray($data['options']);
    }

    private function logInFirstUser($client): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(\App\Entity\User::class)->findOneBy([]);
        if ($user !== null) {
            $client->loginUser($user);
        }
    }
}
