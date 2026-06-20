<?php

namespace App\Service;

use App\Entity\ApplicableLaw;

final class TransparencyCouncilResolver
{
    public const STATE_COUNCIL = 'Consejo de Transparencia y Buen Gobierno';

    public function forLaw(ApplicableLaw $law): string
    {
        return $law->getComplaintOrganism()?->getName() ?? self::STATE_COUNCIL;
    }
}
