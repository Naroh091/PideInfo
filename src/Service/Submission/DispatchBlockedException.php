<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Thrown by {@see RequestDispatcher::dispatchOne()} when a single request cannot
 * be dispatched. Carries a machine code so the HTTP controller maps it to its
 * existing JSON error shapes and the MCP tool maps it to a tool error message.
 */
final class DispatchBlockedException extends \RuntimeException
{
    public const REASON_INCOMPLETE_DRAFT = 'incomplete_draft';
    public const REASON_TITLE_TOO_LONG_FOR_REG = 'title_too_long_for_reg';
    public const REASON_ACTIVE_TASK = 'active_task';
    public const REASON_UNCERTAIN_NEEDS_CONFIRMATION = 'uncertain_needs_confirmation';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
