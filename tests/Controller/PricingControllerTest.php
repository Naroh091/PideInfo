<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Public pricing landing (spec §7): comparison, CTAs and clear VAT.
 */
final class PricingControllerTest extends WebTestCase
{
    public function testPricingPageRendersComparisonAndCtas(): void
    {
        $client = static::createClient();
        $client->request('GET', '/planes');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();

        foreach (['Individual', 'Profesional', 'Equipo', 'Organización'] as $plan) {
            self::assertStringContainsString($plan, $html);
        }
        // Precios mensuales y anuales (= 11 meses).
        foreach (['15', '165', '49', '539', '99', '1089'] as $price) {
            self::assertStringContainsString($price, $html);
        }
        // Cupos por lote.
        foreach (['25 destinatarios', '50 destinatarios', 'tandas'] as $quota) {
            self::assertStringContainsString($quota, $html);
        }
        // CTAs exactos del briefing.
        foreach ([
            'Gestionar solicitudes gratis',
            'Elegir Profesional',
            'Crear un equipo',
            'Crear organización',
            'Solicitar propuesta',
        ] as $cta) {
            self::assertStringContainsString($cta, $html);
        }
        // Plan Privado: a medida, sin Stripe, sin promesas de privacidad.
        self::assertStringContainsString('Privado', $html);
        self::assertStringContainsString('A medida', $html);
        self::assertStringContainsString('Donar', $html);
        self::assertStringContainsString('buymeacoffee.com/naroh', $html);
        // IVA y condiciones claros.
        self::assertStringContainsString('IVA', $html);
        self::assertStringContainsString('Un mes gratis', $html);
        self::assertStringContainsString('data-lucide="star"', $html);
        self::assertStringNotContainsString('11 meses', $html);
        self::assertStringNotContainsString('11 mensualidades', $html);
        self::assertStringContainsString('Stripe', $html);
    }

    public function testPricingGridHasFourPaidCardsPlusFreeBanner(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/planes');

        self::assertResponseIsSuccessful();
        // Individual fuera de la rejilla, como tarjeta horizontal previa…
        self::assertCount(1, $crawler->filter('[data-testid="precios-gratis"]'));
        // …y los cuatro de pago en la rejilla (una fila en escritorio).
        self::assertCount(4, $crawler->filter('[data-testid="precios-grid"] [data-testid="precios-tarjeta"]'));
        // Sin el ticker/eyebrow retirado.
        self::assertStringNotContainsString(
            'Precios claros, sin letra pequeña',
            $client->getResponse()->getContent(),
        );
        // Equipo destacado con mesura.
        self::assertCount(1, $crawler->filter('.precios-card--destacada .precios-nombre:contains("Equipo")'));
    }

    public function testPricingLinkedFromPublicNav(): void
    {
        $client = static::createClient();
        $client->request('GET', '/planes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/planes"]');
    }

    public function testLegacyPreciosRedirectsPermanently(): void
    {
        $client = static::createClient();
        $client->request('GET', '/precios');

        self::assertResponseRedirects('/planes', 301);

        $client->request('GET', '/precios/privado');

        self::assertResponseRedirects('/planes/privado', 301);
    }
}
