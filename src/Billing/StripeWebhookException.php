<?php

declare(strict_types=1);

namespace App\Billing;

final class StripeWebhookException extends \RuntimeException
{
    public function __construct(string $code, ?\Throwable $previous = null)
    {
        parent::__construct($code, 0, $previous);
    }
}
