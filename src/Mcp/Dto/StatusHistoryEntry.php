<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\StatusHistory;

final readonly class StatusHistoryEntry
{
    public function __construct(
        public string $id,
        public string $statusType,
        public string $statusTypeLabel,
        public string $fromStatus,
        public string $fromStatusLabel,
        public string $toStatus,
        public string $toStatusLabel,
        public ?string $notes,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(StatusHistory $history): self
    {
        return new self(
            id: $history->getId()->toRfc4122(),
            statusType: $history->getStatusType(),
            statusTypeLabel: $history->getStatusTypeLabel(),
            fromStatus: $history->getFromStatus(),
            fromStatusLabel: $history->getFromStatusLabel(),
            toStatus: $history->getToStatus(),
            toStatusLabel: $history->getToStatusLabel(),
            notes: $history->getNotes(),
            createdAt: $history->getCreatedAt()->format(\DATE_ATOM),
        );
    }
}
