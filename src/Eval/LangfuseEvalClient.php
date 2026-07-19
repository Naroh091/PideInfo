<?php

declare(strict_types=1);

namespace App\Eval;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the Langfuse public-API surfaces the evaluation harness
 * needs and that neither LangfuseAdminClient (prompts only) nor the OTLP
 * tracing path cover: datasets, dataset items, dataset run items, scores,
 * trace ingestion, and reading observations back (trace mining).
 *
 * Mirrors the request pattern of {@see \App\Prompt\LangfuseAdminClient}.
 */
final class LangfuseEvalClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'LANGFUSE_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'LANGFUSE_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'LANGFUSE_SECRET_KEY')]
        private readonly string $secretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->publicKey !== '' && $this->secretKey !== '';
    }

    /** Creates the dataset if missing; tolerates it already existing. */
    public function ensureDataset(string $name, ?string $description = null): void
    {
        try {
            $payload = ['name' => $name];
            if ($description !== null) {
                $payload['description'] = $description;
            }
            $this->request('POST', '/api/public/v2/datasets', $payload);
        } catch (\RuntimeException $e) {
            // 409/conflict = already exists — fine.
            if (!str_contains($e->getMessage(), 'HTTP 409')) {
                throw $e;
            }
        }
    }

    /**
     * Langfuse upserts dataset items on client-provided id, so re-syncing the
     * canonical YAML is idempotent.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expectedOutput
     * @param array<string, mixed> $metadata
     */
    public function upsertDatasetItem(string $datasetName, string $id, array $input, array $expectedOutput, array $metadata = []): void
    {
        $this->request('POST', '/api/public/dataset-items', [
            'datasetName' => $datasetName,
            'id' => $id,
            'input' => $input,
            'expectedOutput' => $expectedOutput,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Creates a trace through the ingestion API so run items and scores have
     * something to attach to. Returns the trace id.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @param array<string, mixed> $metadata
     */
    public function createTrace(string $name, array $input, array $output, array $metadata = []): string
    {
        $traceId = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED);

        $this->request('POST', '/api/public/ingestion', [
            'batch' => [[
                'id' => Uuid::v7()->toRfc4122(),
                'type' => 'trace-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $traceId,
                    'timestamp' => $now,
                    'name' => $name,
                    'input' => $input,
                    'output' => $output,
                    'metadata' => $metadata,
                ],
            ]],
        ]);

        return $traceId;
    }

    /** @param array<string, mixed> $metadata */
    public function createDatasetRunItem(string $runName, string $datasetItemId, string $traceId, array $metadata = []): void
    {
        $this->request('POST', '/api/public/dataset-run-items', [
            'runName' => $runName,
            'datasetItemId' => $datasetItemId,
            'traceId' => $traceId,
            'metadata' => $metadata,
        ]);
    }

    public function createScore(string $traceId, string $name, float $value, ?string $comment = null): void
    {
        $payload = [
            'id' => Uuid::v7()->toRfc4122(),
            'traceId' => $traceId,
            'name' => $name,
            'value' => $value,
            'dataType' => 'NUMERIC',
        ];
        if ($comment !== null) {
            $payload['comment'] = $comment;
        }

        $this->request('POST', '/api/public/scores', $payload);
    }

    /**
     * Pages through observations (GET /api/public/observations), optionally
     * filtered by observation name. Used by the trace miner to recover the
     * deep-review verdicts the agent already emitted in production.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listObservations(?string $name, int $page = 1, int $limit = 50): array
    {
        $query = ['page' => $page, 'limit' => $limit];
        if ($name !== null && $name !== '') {
            $query['name'] = $name;
        }

        $result = $this->request('GET', '/api/public/observations?' . http_build_query($query), null);

        return [
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $options = [
            'auth_basic' => [$this->publicKey, $this->secretKey],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);

        // The ingestion endpoint answers 207 multi-status; only >=400 is an error.
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Langfuse API %s %s failed (HTTP %d): %s', $method, $path, $status, $content));
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
