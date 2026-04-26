<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AccessRequest;

final readonly class DeadlineEntry
{
    public function __construct(
        public string $requestId,
        public ?string $externalId,
        public string $title,
        public string $publicBody,
        public string $deadlineAt,
        public int $daysRemaining,
        public string $status,
        public bool $isOverdue,
    ) {
    }

    public static function fromEntity(AccessRequest $request, \DateTimeImmutable $now): self
    {
        $deadline = $request->getDeadlineAt() ?? $now;
        $diff = (int) $now->diff($deadline)->format('%r%a');

        return new self(
            requestId: $request->getId()->toRfc4122(),
            externalId: $request->getExternalId(),
            title: $request->getTitle(),
            publicBody: $request->getPublicBody()->getName(),
            deadlineAt: $deadline->format(\DATE_ATOM),
            daysRemaining: $diff,
            status: $request->getStatus(),
            isOverdue: $diff < 0,
        );
    }
}
