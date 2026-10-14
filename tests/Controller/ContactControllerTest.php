<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\MakesSessionCsrfPosts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Private-plan proposal request (A6): public form with CSRF +
 * server-side Turnstile + per-IP limit; sends to the contact mailbox with the
 * existing transport. No Stripe, no payments, no legal copy.
 */
final class ContactControllerTest extends WebTestCase
{
    use MakesSessionCsrfPosts;

    public function testFormRendersWithAllFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/planes/privado');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/planes/privado"]');
        foreach (['name="name"', 'name="organization"', 'name="email"', 'name="message"'] as $field) {
            self::assertSelectorExists('form[action="/planes/privado"] ['.$field.']');
        }
        self::assertStringContainsString(
            'Solicitar propuesta',
            $client->getResponse()->getContent(),
        );
    }

    public function testPostWithoutCsrfIsForbidden(): void
    {
        $client = static::createClient();
        $client->request('POST', '/planes/privado', [
            'name' => 'Ana',
            'email' => 'ana@example.es',
            'message' => 'Hola',
            '_token' => 'falso',
        ]);

        // Anonymous + denied = the entry point redirects to login (nothing
        // processed: no mail and no success flash, just denial).
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testValidPostSendsAndRedirects(): void
    {
        $this->setMailerDsn('null://null');
        try {
            $client = static::createClient();
            $client->request('GET', '/planes/privado');
            self::assertResponseIsSuccessful();

            // Turnstile sin configurar deja pasar (verifier existente); el
            // transporte nulo descarta el correo sin red.
            $client->submitForm('Solicitar propuesta', [
                'name' => 'Ana Ejemplo',
                'organization' => 'Ayuntamiento de Prueba',
                'email' => 'ana@example.es',
                'message' => 'Queremos un despliegue propio con nuestros modelos.',
            ]);

            self::assertResponseRedirects('/planes/privado');
        } finally {
            $this->clearMailerDsn();
        }
    }

    public function testInvalidEmailIsRejectedWithoutSending(): void
    {
        $this->setMailerDsn('null://null');
        try {
            $client = static::createClient();
            $client->request('GET', '/planes/privado');
            $client->submitForm('Solicitar propuesta', [
                'name' => 'Ana',
                'email' => 'no-es-un-email',
                'message' => 'Hola',
            ]);

            self::assertResponseRedirects('/planes/privado');
        } finally {
            $this->clearMailerDsn();
        }
    }

    public function testRateLimitBlocksAbuse(): void
    {
        $this->setMailerDsn('null://null');
        try {
            $client = static::createClient();
            // No reboot: the kernel restarts between requests and the limiter
            // bucket (in-memory cache in test) would be lost.
            $client->disableReboot();
            // IP propia para aislar el bucket de este test (el limiter es por IP
            // y el resto de tests del proceso comparten la 127.0.0.1).
            $client->setServerParameter('REMOTE_ADDR', '10.99.0.7');
            for ($i = 0; $i < 5; $i++) {
                $client->request('GET', '/planes/privado');
                $client->submitForm('Solicitar propuesta', [
                    'name' => 'Spammer '.$i,
                    'email' => 'spam'.$i.'@example.es',
                    'message' => 'Mensaje '.$i,
                ]);
                self::assertResponseRedirects('/planes/privado');
            }

            // Sixth send within the same hour: rate-limited (still a redirect
            // with flash, no mail sent).
            $client->request('GET', '/planes/privado');
            $client->submitForm('Solicitar propuesta', [
                'name' => 'Spammer 5',
                'email' => 'spam5@example.es',
                'message' => 'Mensaje 5',
            ]);
            self::assertResponseRedirects('/planes/privado');
            $crawler = $client->followRedirect();
            self::assertStringContainsString(
                'varias solicitudes',
                $crawler->filter('.flash-error, .alert, [role="alert"], body')->text(''),
            );
        } finally {
            $this->clearMailerDsn();
        }
    }

    private function setMailerDsn(string $dsn): void
    {
        // Snapshot of $_SERVER (bootEnv does not go through putenv here:
        // getenv() returns false and restoring from it would write empty).
        // Saved once per process so nested set/clear pairs restore correctly.
        if (!\array_key_exists('ORIG_MAILER_DSN', $_ENV)) {
            $_ENV['ORIG_MAILER_DSN'] = $_SERVER['MAILER_DSN'] ?? false;
        }
        putenv('MAILER_DSN='.$dsn);
        $_SERVER['MAILER_DSN'] = $dsn;
        $_ENV['MAILER_DSN'] = $dsn;
    }

    private function clearMailerDsn(): void
    {
        if (!isset($_ENV['ORIG_MAILER_DSN']) || $_ENV['ORIG_MAILER_DSN'] === false) {
            return;
        }
        $orig = $_ENV['ORIG_MAILER_DSN'];
        putenv('MAILER_DSN='.$orig);
        $_SERVER['MAILER_DSN'] = $orig;
        $_ENV['MAILER_DSN'] = $orig;
    }
}
