<?php

namespace App\Service;

use App\Entity\ApplicableLaw;

final class TransparencyCouncilResolver
{
    public const STATE_COUNCIL = 'Consejo de Transparencia y Buen Gobierno';

    /**
     * Map from autonomous_community.code (3 letters) to the competent transparency council.
     * Communities without a dedicated council (e.g. CEU, MEL) fall back to the state CTBG.
     */
    private const TRANSPARENCY_COUNCILS = [
        'AND' => 'Consejo de Transparencia y Protección de Datos de Andalucía',
        'ARA' => 'Consejo de Transparencia de Aragón',
        'AST' => 'Consejo de Transparencia y Buen Gobierno del Principado de Asturias',
        'BAL' => 'Comissió per a les Reclamacions d\'Accés a la Informació Pública de les Illes Balears',
        'CAN' => 'Comisionado de Transparencia y Acceso a la Información Pública de Canarias',
        'CNT' => 'Consejo de Transparencia de Cantabria',
        'CLM' => 'Consejo Regional de Transparencia y Buen Gobierno de Castilla-La Mancha',
        'CYL' => 'Comisionado de Transparencia de Castilla y León',
        'CAT' => 'Comissió de Garantia del Dret d\'Accés a la Informació Pública',
        'EXT' => 'Consejo de Transparencia y Participación Ciudadana de Extremadura',
        'GAL' => 'Comisionado de Transparencia de Galicia',
        'RIO' => 'Consejo de Transparencia de La Rioja',
        'MAD' => 'Consejo de Transparencia y Participación de la Comunidad de Madrid',
        'MUR' => 'Consejo de la Transparencia de la Región de Murcia',
        'NAV' => 'Consejo de Transparencia de Navarra',
        'PVA' => 'Comisión Vasca de Acceso a la Información Pública',
        'VAL' => 'Consell de Transparència de la Comunitat Valenciana',
    ];

    public function forLaw(ApplicableLaw $law): string
    {
        if ($law->isStateLaw()) {
            return self::STATE_COUNCIL;
        }

        $autonomousCommunity = $law->getAutonomousCommunity();
        if ($autonomousCommunity === null) {
            return self::STATE_COUNCIL;
        }

        return self::TRANSPARENCY_COUNCILS[$autonomousCommunity->getCode()] ?? self::STATE_COUNCIL;
    }
}
