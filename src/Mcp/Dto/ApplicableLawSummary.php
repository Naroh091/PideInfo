<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\ApplicableLaw;

final readonly class ApplicableLawSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $jurisdiction,
        public ?string $ccaa,
        public int $responseDeadlineValue,
        public string $responseDeadlineUnit,
        public int $extensionValue,
        public string $extensionUnit,
        public int $maxExtensions,
        public int $complainDeadlineDays,
        public int $complianceAfterComplainDays,
        public bool $silenceIsPositive,
        public ?string $content,
        public ?string $notes,
        public ?string $officialUrl,
        public ?string $complainOrganism,
    ) {
    }

    public static function fromEntity(ApplicableLaw $law): self
    {
        return new self(
            id: $law->getId()->toRfc4122(),
            name: $law->getName(),
            code: $law->getShortCode(),
            jurisdiction: $law->isStateLaw() ? 'state' : 'autonomous',
            ccaa: $law->getAutonomousCommunity()?->getName(),
            responseDeadlineValue: $law->getResponseDeadlineValue(),
            responseDeadlineUnit: $law->getResponseDeadlineUnit(),
            extensionValue: $law->getExtensionValue(),
            extensionUnit: $law->getExtensionUnit(),
            maxExtensions: $law->getMaxExtensions(),
            complainDeadlineDays: $law->getComplainDeadlineDays(),
            complianceAfterComplainDays: $law->getComplianceAfterComplainDays(),
            silenceIsPositive: $law->isSilenceIsPositive(),
            content: $law->getContent(),
            notes: $law->getNotes(),
            officialUrl: $law->getOfficialUrl(),
            complainOrganism: $law->getComplainOrganism()?->getName(),
        );
    }
}
