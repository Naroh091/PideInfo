<?php

namespace App\Observability;

use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the singleton TracerProvider for the app. Returns a NoopTracerProvider
 * when Langfuse credentials are missing or the exporter cannot be initialized,
 * so missing observability never breaks the app.
 */
final class TracerFactory
{
    public function __construct(
        #[Autowire(env: 'LANGFUSE_BASE_URL')]
        private readonly string $langfuseBaseUrl,
        #[Autowire(env: 'LANGFUSE_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'LANGFUSE_SECRET_KEY')]
        private readonly string $secretKey,
        #[Autowire(env: 'OTEL_SERVICE_NAME')]
        private readonly string $serviceName,
        #[Autowire(env: 'float:OTEL_EXPORTER_OTLP_TIMEOUT')]
        private readonly float $timeoutMillis,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::LANGFUSE_TRACING_ENVIRONMENT')]
        private readonly ?string $tracingEnvironment = null,
    ) {
    }

    public function create(): TracerProviderInterface
    {
        if ($this->langfuseBaseUrl === '' || $this->publicKey === '' || $this->secretKey === '') {
            return new NoopTracerProvider();
        }

        // Route OTel SDK internal warnings/errors (export failures, etc.) through PSR
        // so a Langfuse outage shows up as a warning in app logs instead of stderr.
        new LoggerHolder($this->logger);

        try {
            $endpoint = rtrim($this->langfuseBaseUrl, '/') . '/api/public/otel/v1/traces';
            $auth = 'Basic ' . base64_encode($this->publicKey . ':' . $this->secretKey);

            $transport = (new OtlpHttpTransportFactory())->create(
                endpoint: $endpoint,
                contentType: 'application/x-protobuf',
                headers: ['Authorization' => $auth],
                timeout: max(1.0, $this->timeoutMillis / 1000.0),
            );

            // Langfuse answers OTLP exports with `200 {}` (JSON) instead of a protobuf
            // response; this wrapper stops the exporter from choking on that body.
            $exporter = new SpanExporter(new JsonTolerantOtlpTransport($transport));

            $resourceAttributes = [
                ResourceAttributes::SERVICE_NAME => $this->serviceName !== '' ? $this->serviceName : 'pideinfo',
            ];

            if ($this->tracingEnvironment !== null && $this->tracingEnvironment !== '') {
                $resourceAttributes['deployment.environment'] = $this->tracingEnvironment;
            }

            $resource = ResourceInfoFactory::defaultResource()->merge(
                ResourceInfo::create(Attributes::create($resourceAttributes))
            );

            $processor = new BatchSpanProcessor(
                exporter: $exporter,
                clock: \OpenTelemetry\API\Common\Time\Clock::getDefault(),
                maxQueueSize: 512,
                scheduledDelayMillis: 2000,
                exportTimeoutMillis: (int) $this->timeoutMillis,
                maxExportBatchSize: 256,
                autoFlush: true,
            );

            return new TracerProvider(
                spanProcessors: [$processor],
                resource: $resource,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('OTel TracerProvider init failed, falling back to noop', [
                'error' => $e->getMessage(),
            ]);

            return new NoopTracerProvider();
        }
    }
}
