<?php

declare(strict_types=1);

namespace App\Eval;

use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use OpenTelemetry\API\Trace\Span;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the Langfuse public-API surfaces the evaluation harness
 * needs and that neither LangfuseAdminClient (prompts only) nor the OTLP
 * tracing path cover: datasets, experiments, scores, and reading observations
 * back (trace mining).
 *
 * v4-native: experiment data travels as OTel traces carrying experiment
 * attributes (no legacy /api/public/ingestion trace events, no
 * /dataset-run-items), reads go through Observations API v2.
 *
 * Mirrors the request pattern of {@see \App\Prompt\LangfuseAdminClient}.
 */
final class LangfuseEvalClient
{
    /** @var array<string, array{id: string, datasetId: string}> */
    private array $experimentCache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'LANGFUSE_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'LANGFUSE_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'LANGFUSE_SECRET_KEY')]
        private readonly string $secretKey,
        private readonly Tracer $tracer,
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
     * Records one experiment item as an OTel trace carrying the experiment
     * attributes Langfuse v4 needs to synthesize the experiment — the
     * replacement for the retired trace-create + dataset-run-item pair.
     * Returns the trace id (32 lowercase hex chars).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @param array<string, mixed> $expectedOutput
     * @param array<string, mixed> $metadata
     */
    public function createExperimentTrace(
        string $runName,
        string $datasetName,
        string $itemId,
        string $traceName,
        array $input,
        array $output,
        array $expectedOutput = [],
        array $metadata = [],
    ): string {
        $experiment = $this->resolveExperiment($datasetName, $runName);

        $attributes = [
            AttributeKeys::LANGFUSE_OBSERVATION_INPUT => $this->encode($input),
            AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT => $this->encode($output),
            'langfuse.experiment.id' => $experiment['id'],
            'langfuse.experiment.name' => $runName,
            'langfuse.experiment.dataset.id' => $experiment['datasetId'],
            'langfuse.experiment.item.id' => $itemId,
            'langfuse.experiment.item.expected_output' => $this->encode($expectedOutput),
        ];
        foreach ($metadata as $key => $value) {
            $attributes['langfuse.experiment.item.metadata.'.$key] = $this->scalar($value);
        }

        return $this->tracer->traceRoot(
            $traceName,
            $attributes,
            static function () {
                $span = Span::getCurrent();
                $context = $span->getContext();
                if (!$context->isValid()) {
                    throw new \RuntimeException('OTel tracing is disabled (noop provider); cannot record experiment trace.');
                }
                // Must equal the root span's own id for Langfuse to link item → trace.
                $span->setAttribute('langfuse.experiment.item.root_observation_id', $context->getSpanId());

                return $context->getTraceId();
            },
            traceInput: $this->encode($input),
        );
    }

    /**
     * Creates a plain (non-experiment) trace through OTel so scores have
     * something to attach to. Returns the trace id (32 lowercase hex chars).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     * @param array<string, mixed> $metadata
     */
    public function createTrace(string $name, array $input, array $output, array $metadata = []): string
    {
        $attributes = [
            AttributeKeys::LANGFUSE_TRACE_OUTPUT => $this->encode($output),
        ];
        foreach ($metadata as $key => $value) {
            $attributes['langfuse.trace.metadata.'.$key] = $this->scalar($value);
        }

        return $this->tracer->traceRoot(
            $name,
            $attributes,
            static function () {
                $context = Span::getCurrent()->getContext();
                if (!$context->isValid()) {
                    throw new \RuntimeException('OTel tracing is disabled (noop provider); cannot record trace.');
                }

                return $context->getTraceId();
            },
            traceInput: $this->encode($input),
        );
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

        // POST /api/public/scores stays supported on v4 (score writes are
        // not part of the ingestion deprecation).
        $this->request('POST', '/api/public/scores', $payload);
    }

    /**
     * Pages through observations via Observations API v2 (cursor-based),
     * optionally filtered by observation name. Used by the trace miner to
     * recover the deep-review verdicts the agent already emitted in production.
     *
     * v2 returns input/output as raw strings — callers parse JSON themselves
     * when they need structured values.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listObservations(
        ?string $name,
        ?string $cursor = null,
        int $limit = 50,
        ?string $fromStartTime = null,
        ?string $toStartTime = null,
    ): array {
        $query = [
            'limit' => $limit,
            // v2 requires bounded time ranges on every request.
            'fromStartTime' => $fromStartTime ?? '2020-01-01T00:00:00Z',
            'toStartTime' => $toStartTime ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'fields' => 'core,basic,io,trace_context',
        ];
        if ($name !== null && $name !== '') {
            $query['name'] = $name;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $result = $this->request('GET', '/api/public/v2/observations?'.http_build_query($query), null);

        return [
            'data' => \is_array($result['data'] ?? null) ? $result['data'] : [],
            'meta' => \is_array($result['meta'] ?? null) ? $result['meta'] : [],
        ];
    }

    /**
     * @return array{id: string, datasetId: string}
     */
    private function resolveExperiment(string $datasetName, string $runName): array
    {
        $cacheKey = $datasetName."\0".$runName;
        if (isset($this->experimentCache[$cacheKey])) {
            return $this->experimentCache[$cacheKey];
        }

        $dataset = $this->request('GET', '/api/public/v2/datasets/'.rawurlencode($datasetName), null);
        $datasetId = $dataset['id'] ?? null;
        if (!\is_string($datasetId) || $datasetId === '') {
            throw new \RuntimeException(sprintf('Langfuse dataset "%s" not found; call ensureDataset() first.', $datasetName));
        }

        $found = $this->request('GET', '/api/public/experiments?'.http_build_query([
            'datasetId' => $datasetId,
            'name' => $runName,
            'fromStartTime' => '2020-01-01T00:00:00Z',
            'limit' => 10,
        ]), null);

        $experimentId = null;
        foreach ($found['data'] ?? [] as $row) {
            if (($row['name'] ?? null) === $runName) {
                $experimentId = $row['id'];
                break;
            }
        }
        // Experiments are synthesized from traces carrying the experiment
        // attributes; a fresh client-generated id starts a new one.
        $experimentId ??= Uuid::v7()->toRfc4122();

        return $this->experimentCache[$cacheKey] = ['id' => $experimentId, 'datasetId' => $datasetId];
    }

    /**
     * OTel attribute values must be scalar or flat scalar arrays — encode
     * anything structural as JSON, like the exporter does on the wire.
     *
     * @param mixed $value
     */
    private function scalar(mixed $value): string|int|float|bool|array
    {
        if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }
        if (\is_array($value)) {
            $flat = [];
            foreach ($value as $item) {
                if (\is_scalar($item) || $item === null) {
                    $flat[] = $item;
                }
            }

            return $flat === [] ? $this->encode($value) : array_values($flat);
        }

        return $this->encode($value);
    }

    /**
     * @param mixed $value
     */
    private function encode(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        try {
            return (string) json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return get_debug_type($value);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = rtrim($this->baseUrl, '/').$path;
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

        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Langfuse API %s %s failed (HTTP %d): %s', $method, $path, $status, $content));
        }

        $decoded = json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
