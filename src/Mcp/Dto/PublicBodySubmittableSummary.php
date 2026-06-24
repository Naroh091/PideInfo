<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\PublicBody;

/**
 * A submittable public body: a PublicBody enriched with its submission channel,
 * whether it needs a REG/DIR3 destination, and the resolved applicable law —
 * everything an agent needs to pick a recipient and start a draft.
 */
final readonly class PublicBodySubmittableSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $level,
        public string $levelLabel,
        public ?string $autonomousCommunity,
        public ?string $registryCode,
        public string $taskType,
        public string $channelLabel,
        public bool $requiresRegDestination,
        public ?string $applicableLawId,
        public ?string $applicableLaw,
    ) {
    }

    public static function fromEntity(
        PublicBody $body,
        string $taskType,
        string $channelLabel,
        bool $requiresRegDestination,
        ?string $applicableLawId,
        ?string $applicableLaw,
    ): self {
        return new self(
            id: $body->getId()->toRfc4122(),
            name: $body->getName(),
            level: $body->getLevel(),
            levelLabel: $body->getLevelLabel(),
            autonomousCommunity: $body->getAutonomousCommunity()?->getName(),
            registryCode: $body->getRegistryCode(),
            taskType: $taskType,
            channelLabel: $channelLabel,
            requiresRegDestination: $requiresRegDestination,
            applicableLawId: $applicableLawId,
            applicableLaw: $applicableLaw,
        );
    }
}
