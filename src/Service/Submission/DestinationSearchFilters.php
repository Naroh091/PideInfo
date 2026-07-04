<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Facet filters for {@see DestinationSearch}. `nivel` is the UI key
 * (estado|autonomica|local|justicia|universidades|otros); comunidad/provincia
 * are the raw labels as stored on RegDestination.
 */
final readonly class DestinationSearchFilters
{
    public function __construct(
        public ?string $nivel = null,
        public ?string $comunidad = null,
        public ?string $provincia = null,
    ) {
    }
}
