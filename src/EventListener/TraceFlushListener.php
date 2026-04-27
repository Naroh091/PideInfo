<?php

namespace App\EventListener;

use App\Observability\Tracer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Force-flush pending OTel spans after each HTTP response is sent. The flush
 * runs post-response so it never adds latency to the user-visible request.
 */
#[AsEventListener(event: TerminateEvent::class)]
final class TraceFlushListener
{
    public function __construct(private readonly Tracer $tracer)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $this->tracer->forceFlush();
    }
}
