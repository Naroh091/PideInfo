<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AccessRequest;

/**
 * Detailed serializable view of an AccessRequest including history.
 */
final readonly class RequestDetail
{
    /**
     * @param list<array{from:string,to:string,notes:?string,at:string}> $statusHistory
     * @param list<DocumentSummary>                                       $documents
     */
    public function __construct(
        public AccessRequestSummary $summary,
        public string $description,
        public ?string $courtStatus,
        public ?string $thirdPartyStatus,
        public array $statusHistory,
        public array $documents,
    ) {
    }

    /**
     * @param list<array{from:string,to:string,notes:?string,at:string}> $statusHistory
     * @param list<DocumentSummary>                                       $documents
     */
    public static function fromEntity(AccessRequest $request, array $statusHistory, array $documents): self
    {
        return new self(
            summary: AccessRequestSummary::fromEntity($request),
            description: $request->getDescription(),
            courtStatus: $request->getCourtStatus(),
            thirdPartyStatus: $request->getThirdPartyStatus(),
            statusHistory: $statusHistory,
            documents: $documents,
        );
    }
}
