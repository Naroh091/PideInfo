<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat;

use App\Service\AI\Chat\StreamHeartbeat;
use PHPUnit\Framework\TestCase;

final class StreamHeartbeatTest extends TestCase
{
    public function testBeatWithoutSinkIsNoOp(): void
    {
        $heartbeat = new StreamHeartbeat(0.0);

        $heartbeat->beat();

        $this->addToAssertionCount(1);
    }

    public function testBeatWaitsForTheIntervalSinceStart(): void
    {
        $calls = 0;
        $heartbeat = new StreamHeartbeat(3600.0);
        $heartbeat->start(function () use (&$calls): void {
            $calls++;
        });

        $heartbeat->beat();

        $this->assertSame(0, $calls);
    }

    public function testBeatCallsSinkOnceTheIntervalElapsed(): void
    {
        $calls = 0;
        $heartbeat = new StreamHeartbeat(0.0);
        $heartbeat->start(function () use (&$calls): void {
            $calls++;
        });

        $heartbeat->beat();
        $heartbeat->beat();

        $this->assertSame(2, $calls);
    }

    public function testTouchPostponesTheNextBeat(): void
    {
        $calls = 0;
        $heartbeat = new StreamHeartbeat(3600.0);
        $heartbeat->start(function () use (&$calls): void {
            $calls++;
        });

        $heartbeat->touch();
        $heartbeat->beat();

        $this->assertSame(0, $calls);
    }

    public function testStopAndResetDetachTheSink(): void
    {
        $calls = 0;
        $sink = function () use (&$calls): void {
            $calls++;
        };
        $heartbeat = new StreamHeartbeat(0.0);

        $heartbeat->start($sink);
        $heartbeat->stop();
        $heartbeat->beat();

        $heartbeat->start($sink);
        $heartbeat->reset();
        $heartbeat->beat();

        $this->assertSame(0, $calls);
    }
}
