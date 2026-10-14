<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Thrown when the subscription is overdue past grace or unpaid
 * (assumption A3): the account goes read-only — viewing, downloading and
 * exporting still work; new submissions, members and invitations do not.
 */
final class SubscriptionReadOnlyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('subscription_read_only');
    }
}
