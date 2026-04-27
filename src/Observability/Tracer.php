<?php

namespace App\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;

/**
 * Thin facade for instrumentation. All helpers ensure the user callable runs
 * (and its return value/exception is preserved) regardless of any tracing failure.
 */
final class Tracer
{
    private readonly TracerInterface $tracer;

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
    ) {
        $this->tracer = $tracerProvider->getTracer('app.pideinfo');
    }

    /**
     * Open a root span — becomes a "trace" in Langfuse — run the callable inside
     * its scope, force-flush so spans appear promptly, and return the result.
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable():T $fn
     * @return T
     */
    public function traceRoot(string $name, array $attributes, callable $fn): mixed
    {
        $attributes[AttributeKeys::LANGFUSE_TRACE_NAME] ??= $name;

        $span = $this->startSpan($name, SpanKind::KIND_SERVER, $attributes);
        $scope = $span?->activate();

        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->recordException($span, $e);
            throw $e;
        } finally {
            $scope?->detach();
            $span?->end();
            $this->safeForceFlush();
        }
    }

    /**
     * Open a child span and run the callable inside its scope. Generic wrapper for
     * non-LLM internal steps (retrieval, batched loops, etc.).
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable():T $fn
     * @return T
     */
    public function span(string $name, array $attributes, callable $fn): mixed
    {
        $span = $this->startSpan($name, SpanKind::KIND_INTERNAL, $attributes);
        $scope = $span?->activate();

        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->recordException($span, $e);
            throw $e;
        } finally {
            $scope?->detach();
            $span?->end();
        }
    }

    /**
     * Open a child span tagged as a Langfuse "generation". Output is captured by
     * inspecting the callable's return value via $captureOutput; pass null to skip.
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable():T $fn
     * @param ?callable(T, SpanInterface):void $captureOutput Receives the result + active span to set output attributes / usage.
     * @return T
     */
    public function generation(string $name, array $attributes, callable $fn, ?callable $captureOutput = null): mixed
    {
        $attributes[AttributeKeys::LANGFUSE_OBSERVATION_TYPE] ??= 'generation';

        $span = $this->startSpan($name, SpanKind::KIND_CLIENT, $attributes);
        $scope = $span?->activate();

        try {
            $result = $fn();
            if ($captureOutput !== null && $span !== null) {
                try {
                    $captureOutput($result, $span);
                } catch (\Throwable $e) {
                    // never let observability hooks break the call
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordException($span, $e);
            throw $e;
        } finally {
            $scope?->detach();
            $span?->end();
        }
    }

    public function forceFlush(): void
    {
        $this->safeForceFlush();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function startSpan(string $name, int $kind, array $attributes): ?SpanInterface
    {
        try {
            $builder = $this->tracer->spanBuilder($name)->setSpanKind($kind);
            foreach ($attributes as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $builder->setAttribute($key, $value);
            }

            return $builder->startSpan();
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordException(?SpanInterface $span, \Throwable $e): void
    {
        if ($span === null) {
            return;
        }
        try {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        } catch (\Throwable) {
            // swallow
        }
    }

    private function safeForceFlush(): void
    {
        try {
            $provider = $this->tracerProvider;
            if (method_exists($provider, 'forceFlush')) {
                $provider->forceFlush();
            }
        } catch (\Throwable) {
            // swallow
        }
    }
}
