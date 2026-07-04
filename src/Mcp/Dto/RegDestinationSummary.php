<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\RegDestination;

/**
 * Serializable summary of a REG destination for MCP responses. `submissionTargetId`
 * is the PublicBody UUID the caller passes as `publicBodyId` to
 * `generate_access_request`; `id` is the RegDestination UUID it passes as
 * `regDestinationId`.
 */
final readonly class RegDestinationSummary
{
    public function __construct(
        public string $id,
        public string $dir3Code,
        public string $displayLabel,
        public string $name,
        public string $submissionTargetId,
        public string $submissionTargetName,
        public ?string $comunidad,
        public ?string $provincia,
        public ?string $nivelAdministracion,
        public ?float $score,
    ) {
    }

    public static function fromEntity(RegDestination $destination, ?float $score = null): self
    {
        return new self(
            id: $destination->getId()->toRfc4122(),
            dir3Code: $destination->getDir3Code(),
            displayLabel: $destination->getDisplayLabel(),
            name: $destination->getName(),
            submissionTargetId: $destination->getSubmissionTarget()->getId()->toRfc4122(),
            submissionTargetName: $destination->getSubmissionTarget()->getName(),
            comunidad: $destination->getComunidad(),
            provincia: $destination->getProvincia(),
            nivelAdministracion: $destination->getNivelAdministracion(),
            score: $score,
        );
    }
}
