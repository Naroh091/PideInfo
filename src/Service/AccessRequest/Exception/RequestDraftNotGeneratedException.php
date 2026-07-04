<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Exception;

/**
 * Thrown when the model decides it cannot draft the request yet (it asks for
 * more context instead of emitting a draft). Carries the model's reply so the
 * caller can relay it to the user.
 */
final class RequestDraftNotGeneratedException extends \RuntimeException
{
    public function __construct(public readonly string $reply)
    {
        parent::__construct($reply !== '' ? $reply : 'The assistant needs more context before drafting the request.');
    }
}
