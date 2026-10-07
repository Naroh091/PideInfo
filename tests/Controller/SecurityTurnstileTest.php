<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Turnstile end to end on the two account entry points.
 *
 * Both keys are forced here because the whole feature is gated on them being
 * non-empty — the rest of the suite runs with them unset, which is exactly
 * the "Turnstile not configured" path (no widget, nothing verified).
 */
final class SecurityTurnstileTest extends WebTestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private function enableTurnstile(): void
    {
        foreach (['TURNSTILE_SITE_KEY' => '1x00000000000000000000AA', 'TURNSTILE_SECRET_KEY' => '2x0000000000000000000000000000000AA'] as $name => $value) {
            $this->originalEnv[$name] = $_SERVER[$name] ?? false;
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->originalEnv = [];

        parent::tearDown();
    }

    public function testLoginAndRegisterRenderNoWidgetWhenNotConfigured(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('cf-turnstile', (string) $client->getResponse()->getContent());

        $client->request('GET', '/registro');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('cf-turnstile', (string) $client->getResponse()->getContent());
    }

    public function testWidgetIsRenderedInsideBothFormsWhenConfigured(): void
    {
        $this->enableTurnstile();
        $client = static::createClient();

        foreach (['/login', '/registro'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            // Inside the <form>, or api.js would not inject the token field.
            self::assertCount(1, $crawler->filter('form div.cf-turnstile'), $path);
            self::assertCount(1, $crawler->filter('script[src*="challenges.cloudflare.com"]'), $path);
        }
    }

    public function testRegistrationIsRejectedWithoutAToken(): void
    {
        $this->enableTurnstile();
        $client = static::createClient();

        $crawler = $client->request('GET', '/registro');
        $form = $crawler->selectButton('Crear cuenta')->form();
        $email = 'turnstile-' . bin2hex(random_bytes(4)) . '@example.test';
        $form['registration_form[firstName]'] = 'Robot';
        $form['registration_form[lastName]'] = 'Sin Captcha';
        $form['registration_form[email]'] = $email;
        $form['registration_form[plainPassword][first]'] = 'contrasena-larga';
        $form['registration_form[plainPassword][second]'] = 'contrasena-larga';
        $form['registration_form[agreeTerms]'] = '1';

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no eres un robot', (string) $client->getResponse()->getContent());

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        self::assertNull($users->findOneBy(['email' => $email]), 'No account may be created without a token');
    }

    public function testLoginIsRejectedWithoutAToken(): void
    {
        $this->enableTurnstile();
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();
        $form['_username'] = 'nadie@example.test';
        $form['_password'] = 'lo-que-sea';

        $client->submit($form);

        $client->followRedirect();
        self::assertStringContainsString('no eres un robot', (string) $client->getResponse()->getContent());
    }
}
