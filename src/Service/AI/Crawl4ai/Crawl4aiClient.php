<?php

declare(strict_types=1);

namespace App\Service\AI\Crawl4ai;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal client for the Crawl4AI Docker server REST API.
 *
 * Crawl4AI provides fast, structured web scraping via Playwright + Chromium.
 * Unlike CamoFox (which is for interactive browser sessions), Crawl4AI is
 * designed for one-shot URL extraction: send a URL, get back markdown, HTML,
 * links, and metadata in a single HTTP call.
 *
 * The Evomi residential proxy is configured server-side in Crawl4AI's
 * config.yml (mounted as a volume in the Coolify container), so this client
 * does not send proxy_config in the API request — Crawl4AI v0.9.1 rejects
 * proxy_config from untrusted requests anyway.
 *
 * API docs: https://docs.crawl4ai.com/core/self-hosting/
 */
final class Crawl4aiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $endpoint,
        private readonly string $apiToken,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crawl a single URL and return the structured result.
     *
     * @param string $url URL to crawl
     * @return array<string, mixed> Result with keys: markdown, html, links, metadata, etc.
     */
    public function crawl(string $url): array
    {
        $payload = [
            'urls' => [$url],
            'browser_config' => [
                'type' => 'BrowserConfig',
                'params' => ['headless' => true],
            ],
            'crawler_config' => [
                'type' => 'CrawlerRunConfig',
                'params' => ['cache_mode' => 'bypass'],
            ],
        ];

        $response = $this->httpClient->request('POST', $this->endpoint . '/crawl', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiToken,
            ],
            'json' => $payload,
            'timeout' => 60,
        ]);

        $data = $response->toArray();

        if (!($data['success'] ?? false) || empty($data['results'])) {
            $this->logger->warning('Crawl4AI crawl failed', [
                'url' => $url,
                'response' => $data,
            ]);
            return ['error' => $data['error'] ?? 'Crawl failed', 'markdown' => '', 'links' => []];
        }

        $result = $data['results'][0];

        return [
            'url' => $result['url'] ?? $url,
            'markdown' => $result['markdown']['raw_markdown'] ?? '',
            'html' => $result['html'] ?? '',
            'links' => $result['links']['internal'] ?? [],
            'external_links' => $result['links']['external'] ?? [],
            'metadata' => $result['metadata'] ?? [],
            'status_code' => $result['status_code'] ?? 0,
            'success' => true,
        ];
    }
}
