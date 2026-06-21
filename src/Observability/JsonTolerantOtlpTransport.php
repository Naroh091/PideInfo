<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * Wraps the OTLP/HTTP transport to tolerate Langfuse's non-standard OTLP response.
 *
 * Langfuse's OTLP traces endpoint answers `200 OK` with a JSON body `{}` instead
 * of an (empty) protobuf `ExportTraceServiceResponse`. The OTel SDK's SpanExporter
 * unconditionally hydrates the response body as protobuf, so the JSON bytes (`{`,
 * wire type 3/7) blow up with `GPBDecodeException: Unexpected wire type`, and every
 * otherwise-successful export (HTTP 200, spans ingested) is logged as a failure.
 *
 * We intercept the resolved response body and, when it is empty or clearly JSON
 * (starts with `{` or `[`), hand the exporter an empty string. An empty body
 * hydrates to a successful empty response, so the spurious export errors disappear
 * while real protobuf responses (e.g. a genuine partial-success) still pass through.
 *
 * @implements TransportInterface<string>
 */
final class JsonTolerantOtlpTransport implements TransportInterface
{
    /** @param TransportInterface<string> $inner */
    public function __construct(private readonly TransportInterface $inner)
    {
    }

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->inner->send($payload, $cancellation)->map(static function (?string $body): ?string {
            if ($body === null || $body === '') {
                return $body;
            }

            $trimmed = ltrim($body);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                return '';
            }

            return $body;
        });
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
