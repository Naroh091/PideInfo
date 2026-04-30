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
     * Optional hooks let the caller surface the trace-level Input / Output that
     * Langfuse renders in the Trace detail view. Pass `traceInput` for a value
     * that is known up-front (user prompt, message body, …) — it's set on the
     * span before `$fn` runs so it's persisted even on errors. Pass
     * `captureOutput` to let the caller serialize the result onto the span
     * after `$fn` returns.
     *
     * @template T
     * @param array<string, mixed> $attributes
     * @param callable():T $fn
     * @param ?callable(T, SpanInterface):void $captureOutput Receives the result + active span to set trace-level output.
     * @param string|null $traceInput Optional user-facing input; set on the root span as `langfuse.trace.input`.
     * @return T
     */
    public function traceRoot(string $name, array $attributes, callable $fn, ?callable $captureOutput = null, ?string $traceInput = null): mixed
    {
        $attributes[AttributeKeys::LANGFUSE_TRACE_NAME] ??= $name;
        if ($traceInput !== null) {
            $attributes[AttributeKeys::LANGFUSE_TRACE_INPUT] ??= $traceInput;
        }

        $span = $this->startSpan($name, SpanKind::KIND_SERVER, $attributes);
        $scope = $span?->activate();

        try {
            $result = $fn();
            if ($captureOutput !== null && $span !== null) {
                try {
                    $captureOutput($result, $span);
                } catch (\Throwable) {
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

    /**
     * Streaming counterpart of traceRoot(): wraps a Generator under a server-kind
     * root span (a Langfuse "trace"). Yields whatever the inner generator yields
     * and preserves its return value via $gen->getReturn().
     *
     * Like traceRoot(), accepts optional `captureOutput` (fed the generator's
     * return value) and `traceInput` to persist the trace-level I/O that
     * Langfuse renders in the Trace detail view.
     *
     * @template TYield
     * @template TReturn
     * @param array<string, mixed> $attributes
     * @param \Generator<int, TYield, void, TReturn> $gen
     * @param ?callable(TReturn, SpanInterface):void $captureOutput
     * @return \Generator<int, TYield, void, TReturn>
     */
    public function traceRootStream(string $name, array $attributes, \Generator $gen, ?callable $captureOutput = null, ?string $traceInput = null): \Generator
    {
        $attributes[AttributeKeys::LANGFUSE_TRACE_NAME] ??= $name;
        if ($traceInput !== null) {
            $attributes[AttributeKeys::LANGFUSE_TRACE_INPUT] ??= $traceInput;
        }

        $span = $this->startSpan($name, SpanKind::KIND_SERVER, $attributes);
        $scope = $span?->activate();

        try {
            foreach ($gen as $chunk) {
                yield $chunk;
            }

            $result = $gen->getReturn();
            if ($captureOutput !== null && $span !== null) {
                try {
                    $captureOutput($result, $span);
                } catch (\Throwable) {
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
            $this->safeForceFlush();
        }
    }

    /**
     * Streaming counterpart of generation(): wraps a Generator under a Langfuse
     * "generation" span. Yields whatever the inner generator yields and preserves
     * its return value so callers can keep using $gen->getReturn() to read final usage.
     *
     * @template TYield
     * @template TReturn
     * @param array<string, mixed> $attributes
     * @param \Generator<int, TYield, void, TReturn> $gen
     * @param ?callable(TReturn, SpanInterface):void $captureOutput Receives the generator's return value + span on successful completion.
     * @return \Generator<int, TYield, void, TReturn>
     */
    public function generationStream(string $name, array $attributes, \Generator $gen, ?callable $captureOutput = null): \Generator
    {
        $attributes[AttributeKeys::LANGFUSE_OBSERVATION_TYPE] ??= 'generation';

        $span = $this->startSpan($name, SpanKind::KIND_CLIENT, $attributes);
        $scope = $span?->activate();

        try {
            foreach ($gen as $chunk) {
                yield $chunk;
            }

            $result = $gen->getReturn();
            if ($captureOutput !== null && $span !== null) {
                try {
                    $captureOutput($result, $span);
                } catch (\Throwable) {
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
