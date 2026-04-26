<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\PublicBody;

final readonly class PublicBodySummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $level,
        public string $levelLabel,
        public ?string $autonomousCommunity,
        public ?string $registryCode,
    ) {
    }

    public static function fromEntity(PublicBody $publicBody): self
    {
        return new self(
            id: $publicBody->getId()->toRfc4122(),
            name: $publicBody->getName(),
            level: $publicBody->getLevel(),
            levelLabel: $publicBody->getLevelLabel(),
            autonomousCommunity: $publicBody->getAutonomousCommunity()?->getName(),
            registryCode: $publicBody->getRegistryCode(),
        );
    }
}
