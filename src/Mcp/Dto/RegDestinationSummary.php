<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\RegDestination;

/**
 * A concrete DIR3 destination unit for the REG / RED SARA channel, used to fill
 * `regDestinationId` in start_request_draft / submit_request.
 */
final readonly class RegDestinationSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $dir3Code,
        public string $displayLabel,
        public ?string $intermediateOrganismName,
        public ?string $oficinaName,
        public ?string $comunidad,
        public ?string $provincia,
        public ?string $nivelAdministracion,
    ) {
    }

    public static function fromEntity(RegDestination $rd): self
    {
        return new self(
            id: $rd->getId()->toRfc4122(),
            name: $rd->getName(),
            dir3Code: $rd->getDir3Code(),
            displayLabel: $rd->getDisplayLabel(),
            intermediateOrganismName: $rd->getIntermediateOrganismName(),
            oficinaName: $rd->getOficinaName(),
            comunidad: $rd->getComunidad(),
            provincia: $rd->getProvincia(),
            nivelAdministracion: $rd->getNivelAdministracion(),
        );
    }
}
