<?php

namespace App\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the dispatching user identity through the message bus so consumer-side
 * middleware (e.g. tracing) can attribute the resulting work to a specific user.
 */
final readonly class UserContextStamp implements StampInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
