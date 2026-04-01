<?php

namespace App\DTO;

use App\Entity\Resolution;

class ResolutionData
{
    public function __construct(
        public string $referenceNumber,
        public string $outcome,
        public string $source = Resolution::SOURCE_CTBG,
        public string $scope = Resolution::SCOPE_NATIONAL,
        public ?string $subject = null,
        public ?string $publicBodyName = null,
        public ?string $claimReason = null,
        public ?string $sourceUrl = null,
        public ?string $entityType = null,
        public ?string $autonomousCommunityName = null,
        public ?int $entryYear = null,
        /** @var array<string>|null */
        public ?array $topics = null,
        /** @var array<string>|null */
        public ?array $keywords = null,
        /** @var array<string>|null */
        public ?array $challengedActs = null,
        /** @var array<string>|null */
        public ?array $councilCriteria = null,
        /** @var array<string, mixed>|null */
        public ?array $sourceMetadata = null,
        public ?int $year = null,
    ) {
    }
}
