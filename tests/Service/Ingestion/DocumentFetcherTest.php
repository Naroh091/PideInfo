<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion;

use App\Service\Ingestion\DocumentFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DocumentFetcherTest extends TestCase
{
    public function testSendsABrowserUserAgentToTheCtbg(): void
    {
        // Verified live: consejodetransparencia.es answers 404 to any request without a
        // browser-like UA. Every sentencia PDF of the recursos listing lives there, so a
        // missing UA zeroes the whole judgment import — silently.
        $seen = null;

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['normalized_headers']['user-agent'][0] ?? null;

            return new MockResponse('%PDF-contenido');
        });

        $fetcher = new DocumentFetcher($client, new NullLogger());
        $content = $fetcher->fetch('https://consejodetransparencia.es/dam/recursos/R%20CTBG%202016-0105.pdf');

        self::assertSame('%PDF-contenido', $content);
        self::assertNotNull($seen);
        self::assertStringContainsString('Mozilla/5.0', $seen);
    }

    public function testFallsBackToTheWaybackMachine(): void
    {
        $calls = [];

        $client = new MockHttpClient(function (string $method, string $url) use (&$calls) {
            $calls[] = $url;

            if (str_contains($url, 'web.archive.org')) {
                return new MockResponse('%PDF-desde-wayback');
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        $fetcher = new DocumentFetcher($client, new NullLogger());
        $content = $fetcher->fetch('https://example.org/desaparecido.pdf');

        self::assertSame('%PDF-desde-wayback', $content);
        self::assertCount(2, $calls);
        self::assertStringContainsString('web.archive.org/web/https://example.org', $calls[1]);
    }

    public function testReturnsNullWhenEverythingFails(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 500]));

        $fetcher = new DocumentFetcher($client, new NullLogger());

        self::assertNull($fetcher->fetch('https://example.org/roto.pdf'));
    }

    public function testEncodesSpacesAndAccentsInThePathOnly(): void
    {
        // "R CTBG 2023-0752 Resoluci-n expte. 602-2023.pdf" is a REAL filename from the CTBG.
        $requested = null;

        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested = $url;

            return new MockResponse('ok');
        });

        (new DocumentFetcher($client, new NullLogger()))
            ->fetch('https://example.org/dam/R CTBG 2023-0752 Resolución.pdf?v=1');

        self::assertSame('https://example.org/dam/R%20CTBG%202023-0752%20Resoluci%C3%B3n.pdf?v=1', $requested);
    }
}
