<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads source documents (resolution and judgment PDFs, DOC/DOCX) with the per-host
 * quirks the Spanish administration forces on us, and a Wayback Machine fallback.
 *
 * Extracted from ResolutionProcessingTrait::fetchDocumentContent() so the judgment pipeline
 * does not become the third copy of it. The trait still carries its own copy for the
 * resolution importers; migrating them here is a follow-up, not part of this change.
 *
 * The quirks are DATA, one list per behaviour, because every new source seems to bring a new
 * one — and a missing quirk fails silently as "0 PDFs downloaded", which reads like there was
 * simply nothing to do.
 */
final class DocumentFetcher
{
    private const BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Hosts that answer 404/403 to anything that does not look like a browser.
     * consejodetransparencia.es is the one that matters for judgments: EVERY sentencia PDF of
     * the recursos listing lives there, so forgetting it zeroes the whole import — silently.
     */
    private const HOSTS_NEEDING_BROWSER_UA = [
        'comunidad.madrid',
        'consejodetransparencia.es',
    ];

    /** Hosts with an untrusted TLS certificate. */
    private const HOSTS_SKIPPING_TLS_VERIFY = [
        'ctpdandalucia.es',
    ];

    /** Hosts so slow-or-dead that waiting the default 60s per document stalls a whole import. */
    private const HOSTS_WITH_SHORT_TIMEOUT = [
        'gobiernoabierto.navarra.es' => 2,
    ];

    private const DEFAULT_TIMEOUT = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string): void|null $onProgress human-readable progress lines (CLI output)
     *
     * @return string|null null when both the direct download and the Wayback fallback failed
     */
    public function fetch(string $url, ?callable $onProgress = null): ?string
    {
        $url = $this->encodeUrlPath($url);
        $notify = $onProgress ?? static function (string $line): void {};

        try {
            return $this->httpClient->request('GET', $url, $this->optionsFor($url))->getContent();
        } catch (\Throwable $e) {
            $notify(sprintf('Direct download failed: %s', $e->getMessage()));
        }

        // The Spanish administration deletes and reshuffles documents constantly; the Wayback
        // Machine is often the only copy left of an older resolution or sentencia.
        $notify('Retrying via Wayback Machine…');

        try {
            $content = $this->httpClient
                ->request('GET', 'https://web.archive.org/web/' . $url, ['timeout' => self::DEFAULT_TIMEOUT])
                ->getContent();
            $notify('Fetched from Wayback Machine');

            return $content;
        } catch (\Throwable $e) {
            $this->logger->warning('Document download failed (direct and Wayback)', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $notify(sprintf('Wayback fallback failed: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(string $url): array
    {
        $options = ['timeout' => self::DEFAULT_TIMEOUT];

        foreach (self::HOSTS_WITH_SHORT_TIMEOUT as $host => $timeout) {
            if (str_contains($url, $host)) {
                $options['timeout'] = $timeout;
            }
        }

        foreach (self::HOSTS_NEEDING_BROWSER_UA as $host) {
            if (str_contains($url, $host)) {
                $options['headers']['User-Agent'] = self::BROWSER_UA;
            }
        }

        foreach (self::HOSTS_SKIPPING_TLS_VERIFY as $host) {
            if (str_contains($url, $host)) {
                $options['verify_peer'] = false;
            }
        }

        return $options;
    }

    /**
     * Administrations publish URLs with raw spaces and accents in the path; encode each
     * segment (idempotently: decode first) without touching scheme, host or query.
     */
    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['path'])) {
            return $url;
        }

        $segments = explode('/', $parts['path']);
        $parts['path'] = implode('/', array_map(
            static fn (string $s): string => rawurlencode(rawurldecode($s)),
            $segments,
        ));

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        $result .= $parts['path'];
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        return $result;
    }
}
