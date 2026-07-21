<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AccessRequest;

/**
 * Lightweight serializable summary of an AccessRequest for MCP responses.
 */
final readonly class AccessRequestSummary
{
    public function __construct(
        public string $id,
        public ?string $externalId,
        public string $title,
        public string $status,
        public string $statusLabel,
        public string $internalState,
        public string $internalStateLabel,
        public string $publicBody,
        public string $applicableLaw,
        public ?string $sentAt,
        public ?string $deadlineAt,
        public bool $hasComplaint,
    ) {
    }

    public static function fromEntity(AccessRequest $request): self
    {
        return new self(
            id: $request->getId()->toRfc4122(),
            externalId: $request->getExternalId(),
            title: $request->getTitle(),
            status: $request->getStatus(),
            statusLabel: $request->getStatusLabel(),
            internalState: $request->getInternalState(),
            internalStateLabel: $request->getInternalStateLabel(),
            publicBody: $request->getPublicBody()->getName(),
            applicableLaw: $request->getApplicableLaw()->getName(),
            sentAt: $request->getSentAt()?->format(\DATE_ATOM),
            deadlineAt: $request->getDeadlineAt()?->format(\DATE_ATOM),
            hasComplaint: null !== $request->getComplaint(),
        );
    }
}
