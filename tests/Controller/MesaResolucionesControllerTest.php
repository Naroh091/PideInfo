<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La puerta de la mesa de resoluciones. El corpus de la BD de desarrollo puede
 * variar, así que estos tests se centran en el acceso, no en los resultados.
 */
final class MesaResolucionesControllerTest extends WebTestCase
{
    public function testWithoutPasswordRedirectsToAcceso(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mesa-resoluciones');

        self::assertResponseRedirects('/mesa-resoluciones/acceso');
    }

    public function testAccesoPageRendersWithoutLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mesa-resoluciones/acceso');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.acceso-titulo', 'Mesa de resoluciones');
    }

    public function testWrongPasswordShowsErrorAndDoesNotGrant(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'no-es-la-clave');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.acceso-error', 'no es válida');

        $client->request('GET', '/mesa-resoluciones');
        self::assertResponseRedirects('/mesa-resoluciones/acceso');
    }

    public function testCorrectPasswordOpensTheMesa(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'CTBG');

        self::assertResponseRedirects('/mesa-resoluciones');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.barra-nombre', 'Mesa de resoluciones');
    }

    public function testSecondCommaSeparatedPasswordAlsoWorks(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'invitado2026');

        self::assertResponseRedirects('/mesa-resoluciones');
    }

    public function testSalirRevokesAccess(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'CTBG');
        $crawler = $client->followRedirect();

        $client->submit($crawler->filter('form[action$="/mesa-resoluciones/salir"]')->form());
        self::assertResponseRedirects('/mesa-resoluciones/acceso');

        $client->request('GET', '/mesa-resoluciones');
        self::assertResponseRedirects('/mesa-resoluciones/acceso');
    }

    public function testPreguntarRequiresTheGate(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mesa-resoluciones/preguntar', server: ['CONTENT_TYPE' => 'application/json'], content: '{"pregunta": "¿Se puede pedir la agenda de un ministro?"}');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPreguntarRejectsTooShortQuestions(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'CTBG');
        $crawler = $client->request('GET', '/mesa-resoluciones?modo=preguntar');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#respuesta-zona')->attr('data-token');
        self::assertNotEmpty($token);

        $client->request('POST', '/mesa-resoluciones/preguntar', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'pregunta' => 'corta',
            '_token' => $token,
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('pregunta completa', $client->getResponse()->getContent());
    }

    public function testMesaActionsRequireTheGate(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mesa-resoluciones/mesa/fijar', ['id' => 'x']);

        self::assertResponseRedirects('/mesa-resoluciones/acceso');
    }

    public function testExportWithEmptyMesaRedirectsBack(): void
    {
        $client = static::createClient();
        $this->submitPassword($client, 'CTBG');
        $client->request('GET', '/mesa-resoluciones/mesa/exportar');

        self::assertResponseRedirects('/mesa-resoluciones');
    }

    private function submitPassword(KernelBrowser $client, string $password): void
    {
        $crawler = $client->request('GET', '/mesa-resoluciones/acceso');
        $form = $crawler->filter('form.acceso-carta')->form(['password' => $password]);
        $client->submit($form);
    }
}
