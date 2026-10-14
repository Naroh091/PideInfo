<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\MakesSessionCsrfPosts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP billing (spec §6 + assumption A5): without keys → 503 with no charge;
 * webhook with invalid signature → 400; signed event → 200 idempotent.
 */
final class BillingControllerTest extends WebTestCase
{
    use MakesSessionCsrfPosts;
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $orgIds = [];

    protected function tearDown(): void
    {
        if ($this->userIds !== [] || $this->orgIds !== []) {
            $conn = static::getContainer()->get('doctrine')->getConnection();
            if ($this->userIds !== []) {
                $conn->executeStatement(
                    'DELETE FROM "user" WHERE id IN (:ids)',
                    ['ids' => $this->userIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                $this->userIds = [];
            }
            if ($this->orgIds !== []) {
                $conn->executeStatement(
                    'DELETE FROM organization WHERE id IN (:ids)',
                    ['ids' => $this->orgIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                $this->orgIds = [];
            }
        }

        parent::tearDown();
    }
    public function testCheckoutWithoutKeysIsUnavailable(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('buyer-nokeys@example.com');

        $client->loginUser($user);
        $this->postWithSessionToken($client, '/facturacion/checkout', 'billing-checkout', [
            'plan' => 'professional',
            'interval' => 'month',
        ]);

        self::assertResponseStatusCodeSame(503);
    }

    public function testCheckoutWithoutCsrfIsForbidden(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('buyer-csrf@example.com');

        $client->loginUser($user);
        $client->request('POST', '/facturacion/checkout', [
            'plan' => 'professional',
            'interval' => 'month',
            '_token' => 'falso',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChooseAnonymousRedirectsToRegister(): void
    {
        $client = static::createClient();
        $client->request('GET', '/facturacion/elegir?plan=team&interval=month');

        self::assertResponseRedirects('/registro');
    }

    public function testChooseLoggedInGoesToUpgrade(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('buyer-choose@example.com');

        $client->loginUser($user);
        $client->request('GET', '/facturacion/elegir?plan=team&interval=month');

        self::assertResponseRedirects('/facturacion/upgrade?plan=team&interval=month');
    }

    public function testUpgradeRendersSummaryWithConfirm(): void
    {
        // Con clave (ficticia: solo construye el objeto, sin red) se ven los
        // precios y el formulario con CSRF hacia el checkout.
        $this->setEnvKey('STRIPE_SECRET_KEY', 'sk_test_fixture');
        try {
            $client = static::createClient();
            $user = $this->makeUser('buyer-upgrade@example.com');

            $client->loginUser($user);
            $client->request('GET', '/facturacion/upgrade?plan=professional&interval=year');

            self::assertResponseIsSuccessful();
            $html = $client->getResponse()->getContent();
            self::assertStringContainsString('Profesional', $html);
            self::assertStringContainsString('165,00', $html);
            self::assertSelectorExists('form[action="/facturacion/checkout"]');
        } finally {
            $this->clearEnvKey('STRIPE_SECRET_KEY');
        }
    }

    public function testUpgradeWithoutKeysShowsContact(): void
    {        $client = static::createClient();
        $user = $this->makeUser('buyer-upgrade-nokeys@example.com');

        $client->loginUser($user);
        $client->request('GET', '/facturacion/upgrade?plan=team&interval=month');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aún no está disponible', $client->getResponse()->getContent());
    }

    /** @var array<string, string|false> */
    private array $origEnv = [];

    private function setEnvKey(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->origEnv)) {
            $this->origEnv[$name] = $_SERVER[$name] ?? false;
        }
        putenv($name.'='.$value);
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }

    private function clearEnvKey(string $name): void
    {
        $orig = $this->origEnv[$name] ?? false;
        if ($orig === false) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
            return;
        }
        putenv($name.'='.$orig);
        $_SERVER[$name] = $orig;
        $_ENV[$name] = $orig;
    }

    public function testPortalWithoutKeysIsUnavailable(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('buyer-portal@example.com');

        $client->loginUser($user);
        $this->postWithSessionToken($client, '/facturacion/portal', 'billing-portal');
        self::assertResponseStatusCodeSame(503);
        self::assertSame('billing_not_configured', $client->getResponse()->getContent());
    }

    public function testWebhookWithoutSecretIsUnavailable(): void
    {
        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(503);
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $this->setTestWebhookSecret('whsec_test_fixture');
        try {
            $client = static::createClient();
            $client->request(
                'POST',
                '/webhook/stripe',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=123,v1=mala'],
                '{"id":"evt_x","type":"invoice.paid"}',
            );

            self::assertResponseStatusCodeSame(400);
        } finally {
            $this->clearTestWebhookSecret();
        }
    }

    public function testWebhookAcceptsSignedEventTwice(): void
    {        $secret = 'whsec_test_fixture';
        $this->setTestWebhookSecret($secret);
        try {
            $client = static::createClient();
            $eventId = 'evt_http_'.bin2hex(random_bytes(4));
            $payload = json_encode([
                'id' => $eventId,
                'type' => 'customer.discount.created',
                'data' => ['object' => []],
            ]);
            $sig = $this->sign($payload, $secret);

            $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig];
            $client->request('POST', '/webhook/stripe', [], [], $headers, $payload);
            self::assertResponseIsSuccessful();
            $client->request('POST', '/webhook/stripe', [], [], $headers, $payload);
            self::assertResponseIsSuccessful('la entrega duplicada también responde 200');
        } finally {
            $this->clearTestWebhookSecret();
            $em = static::getContainer()->get('doctrine')->getManager();
            $em->getConnection()->executeStatement(
                'DELETE FROM processed_stripe_event WHERE event_id = :id',
                ['id' => $eventId ?? ''],
            );
        }
    }

    public function testCheckoutBlockedWhenStripeSubscriptionExists(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser('buyer-double@example.com');
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('professional')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeSubscriptionId('sub_existing_'.bin2hex(random_bytes(4)));
        $em->persist($sub);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/equipo');
            self::assertResponseIsSuccessful();
            $this->postWithSessionToken($client, '/facturacion/checkout', 'billing-checkout', [
                'plan' => 'professional',
                'interval' => 'month',
            ]);

            // Sin doble cobro: de vuelta al equipo en vez de otro checkout.
            self::assertResponseRedirects('/equipo');
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testDoneWithoutKeysShowsPending(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('buyer-done@example.com');

        $client->loginUser($user);
        $client->request('GET', '/facturacion/listo');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'confirmando',
            $client->getResponse()->getContent(),
        );
    }

    public function testChangeDowngradeOverCapIsRefused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = (new Organization())->setName('Org downgrade '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->orgIds[] = $org->getId()->toRfc4122();
        $owner = $this->makeUser('owner-downgrade@example.com');
        $owner->setOrganization($org);
        $owner->setOrganizationRole(User::ORG_ROLE_OWNER);
        for ($i = 0; $i < 5; $i++) {
            $extra = $this->makeUser('downgrade-'.$i.'-'.bin2hex(random_bytes(2)).'@example.com');
            $extra->setOrganization($org);
        }
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('organization')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeSubscriptionId('sub_downgrade_'.bin2hex(random_bytes(4)));
        $em->persist($sub);
        $em->flush();

        try {
            $client->loginUser($owner);
            $client->request('GET', '/equipo');
            self::assertResponseIsSuccessful();
            $this->postWithSessionToken($client, '/facturacion/cambiar', 'billing-change', [
                'plan' => 'team',
                'interval' => 'month',
            ]);

            // 6 miembros no caben en Equipo (5): redirect con flash, sin cambios.
            self::assertResponseRedirects('/equipo');
            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame('organization', $fresh->getPlan());
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testChangeWithoutKeysIsUnavailable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = (new Organization())->setName('Org changekeys '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->orgIds[] = $org->getId()->toRfc4122();
        $owner = $this->makeUser('owner-changekeys@example.com');
        $owner->setOrganization($org);
        $owner->setOrganizationRole(User::ORG_ROLE_OWNER);
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeSubscriptionId('sub_changekeys_'.bin2hex(random_bytes(4)));
        $em->persist($sub);
        $em->flush();

        try {
            $client->loginUser($owner);
            $client->request('GET', '/equipo');
            $this->postWithSessionToken($client, '/facturacion/cambiar', 'billing-change', [
                'plan' => 'organization',
                'interval' => 'month',
            ]);

            self::assertResponseStatusCodeSame(503);
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testRegisterShowsPlanBannerWhenIntentPending(): void
    {
        $client = static::createClient();
        $client->request('GET', '/facturacion/elegir?plan=team&interval=month');
        self::assertResponseRedirects('/registro');

        $client->request('GET', '/registro');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Después del registro podrás continuar con la selección del plan',
            $client->getResponse()->getContent(),
        );
        self::assertStringContainsString('Equipo', $client->getResponse()->getContent());
    }

    public function testRegisterWithoutIntentShowsNoBanner(): void
    {
        $client = static::createClient();
        $client->request('GET', '/registro');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Después del registro podrás continuar',
            $client->getResponse()->getContent(),
        );
    }

    private function sign(string $payload, string $secret): string
    {
        $ts = time();
        $v1 = hash_hmac('sha256', $ts.'.'.$payload, $secret);

        return sprintf('t=%d,v1=%s', $ts, $v1);
    }

    /**
     * Sets the webhook secret through the three channels Symfony reads
     * (putenv/getenv, $_SERVER and $_ENV): `bootEnv` leaves a stale '' in
     * $_ENV that would win if we only touched one.
     */
    private function setTestWebhookSecret(string $secret): void
    {
        putenv('STRIPE_WEBHOOK_SECRET='.$secret);
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = $secret;
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $secret;
    }

    private function clearTestWebhookSecret(): void
    {
        putenv('STRIPE_WEBHOOK_SECRET');
        unset($_SERVER['STRIPE_WEBHOOK_SECRET'], $_ENV['STRIPE_WEBHOOK_SECRET']);
    }

    private function makeUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId()->toRfc4122();

        return $user;
    }
}
