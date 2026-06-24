<?php

declare(strict_types=1);

namespace App\Service\Complaint;

/**
 * Thrown by {@see ComplaintPresenter} when a complaint cannot be presented.
 * Carries a machine code (and optional context) so the HTTP controller maps it
 * to its existing JSON error shapes and the MCP tool maps it to a tool message.
 */
final class ComplaintPresentException extends \RuntimeException
{
    public const REASON_NO_COMPLAINT_DOCUMENT = 'no_complaint_document';
    public const REASON_NO_FORM_URL_CONFIGURED = 'no_form_url_configured';
    public const REASON_CCAA_NOT_SUPPORTED = 'ccaa_not_supported';
    public const REASON_REQUEST_NOT_COMPLAINABLE = 'request_not_complainable';
    public const REASON_MISSING_DOCUMENTS = 'missing_documents';
    public const REASON_REG_NOT_SUPPORTED = 'reg_not_supported';
    public const REASON_REG_FIELDS_GENERATION_FAILED = 'reg_fields_generation_failed';
    public const REASON_UNCERTAIN_NEEDS_CONFIRMATION = 'uncertain_needs_confirmation';
    public const REASON_ACTIVE_TASK = 'active_task';

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
