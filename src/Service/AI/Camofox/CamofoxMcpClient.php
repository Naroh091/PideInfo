<?php

declare(strict_types=1);

namespace App\Service\AI\Camofox;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal MCP Streamable HTTP client for the CamoFox MCP server.
 *
 * The CamoFox MCP server operates in stateless mode: it does not return an
 * mcp-session-id header, so each tools/call is independent and does not
 * require a prior initialize. This client sends initialize first (in case
 * the server requires it) but gracefully handles the absence of a session ID.
 *
 * The CamoFox MCP server exposes browser tools (web_search, navigate, snapshot,
 * etc.) that let the agent search the web and visit URLs to enrich context
 * before drafting transparency requests, complaints or allegations.
 */
final class CamofoxMcpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calls a tool on the CamoFox MCP server.
     *
     * @param string $toolName  e.g. "web_search", "navigate_and_snapshot"
     * @param array<string, mixed> $arguments Tool arguments
     * @return array<string, mixed> Parsed tool result
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => (object) $arguments,
            ],
        ];

        $response = $this->post($payload);
        $result = $this->parseSseResponse($response);

        if (isset($result['error'])) {
            $this->logger->warning('CamofoxMcpClient tool call error', [
                'tool' => $toolName,
                'error' => $result['error'],
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => 30,
        ]);

        return ['body' => $response->getContent()];
    }

    /**
     * Parses an SSE-format response (event: message\ndata: {...}).
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function parseSseResponse(array $response): array
    {
        $body = $response['body'] ?? '';
        $lines = explode("\n", $body);
        $dataLines = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $dataLines[] = substr($line, 6);
            }
        }

        if ($dataLines === []) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : ['error' => ['message' => 'No data in response']];
        }

        $json = implode("\n", $dataLines);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['error' => ['message' => 'Invalid JSON in SSE response']];
    }
}
