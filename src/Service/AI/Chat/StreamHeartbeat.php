<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps an SSE response alive while the agent is blocked on a long model call.
 *
 * Cloudflare (in front of production) drops a proxied response after 100 s without a
 * byte from the origin, and a single model call (`agent.final-decision`, the tool loop
 * on the teacher model) can take minutes without emitting any event. The chat
 * controller registers a sink — an SSE comment line plus flush — for the lifetime of
 * the stream, and `CustomModelClient` calls {@see beat()} while it waits on the model:
 * from Guzzle's `progress` callback (curl invokes it about once per second even while
 * the server is silent) and on every streamed chunk.
 *
 * Outside an SSE response (workers, commands, JSON endpoints) no sink is registered and
 * beat() is a no-op.
 */
final class StreamHeartbeat implements ResetInterface
{
    private ?\Closure $sink = null;

    private float $lastActivityAt = 0.0;

    public function __construct(
        private readonly float $intervalSeconds = 15.0,
    ) {
    }

    public function start(callable $sink): void
    {
        $this->sink = $sink(...);
        $this->lastActivityAt = microtime(true);
    }

    public function stop(): void
    {
        $this->sink = null;
    }

    /** A real event just went out, so the next heartbeat can wait a full interval. */
    public function touch(): void
    {
        $this->lastActivityAt = microtime(true);
    }

    public function beat(): void
    {
        if ($this->sink === null) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastActivityAt < $this->intervalSeconds) {
            return;
        }

        $this->lastActivityAt = $now;
        ($this->sink)();
    }

    public function reset(): void
    {
        $this->stop();
    }
}
