<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AccessRequestComplaint;

final readonly class ComplaintSummary
{
    public function __construct(
        public string $id,
        public string $requestId,
        public string $requestTitle,
        public string $publicBody,
        public ?string $externalId,
        public string $status,
        public string $statusLabel,
        public ?string $filedAt,
        public ?string $deadlineAt,
        public ?string $complianceDeadlineAt,
        public int $daysUntilDeadline,
        public bool $isOverdue,
    ) {
    }

    public static function fromEntity(AccessRequestComplaint $complaint): self
    {
        $request = $complaint->getAccessRequest();

        return new self(
            id: $complaint->getId()->toRfc4122(),
            requestId: $request->getId()->toRfc4122(),
            requestTitle: $request->getTitle(),
            publicBody: $request->getPublicBody()->getName(),
            externalId: $complaint->getExternalId(),
            status: $complaint->getStatus(),
            statusLabel: $complaint->getStatusLabel(),
            filedAt: $complaint->getFiledAt()?->format(\DATE_ATOM),
            deadlineAt: $complaint->getDeadlineAt()?->format(\DATE_ATOM),
            complianceDeadlineAt: $complaint->getComplianceDeadlineAt()?->format(\DATE_ATOM),
            daysUntilDeadline: $complaint->getDaysUntilDeadline(),
            isOverdue: $complaint->isDeadlinePassed(),
        );
    }
}
