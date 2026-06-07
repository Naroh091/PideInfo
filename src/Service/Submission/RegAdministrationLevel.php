<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Único punto de verdad de la taxonomía de niveles del selector en cascada
 * (issue #82). Las claves UI mapean a los valores de
 * `RegDestination.nivelAdministracion` tal y como vienen del catálogo DIR3.
 *
 * El nivel "estado" además incluye los cuerpos del Portal de Transparencia AGE
 * (que tienen `transparencyPortalAmbId` y normalmente no llevan RegDestination).
 *
 * Espeja el patrón de centralización de ChannelResolver para que repositorio,
 * controlador y plantilla compartan la misma definición.
 */
final class RegAdministrationLevel
{
    public const FACET_MINISTERIO = 'ministerio';
    public const FACET_COMUNIDAD = 'comunidad';
    public const FACET_NONE = 'none';

    /** Clave UI => etiqueta legible (orden de presentación del paso 1). */
    private const LABELS = [
        'estado' => 'Administración del Estado',
        'autonomica' => 'Administración Autonómica',
        'local' => 'Administración Local',
        'justicia' => 'Administración de Justicia',
        'universidades' => 'Universidades',
        'otros' => 'Otras Instituciones',
    ];

    /** Clave UI => valor exacto de RegDestination.nivelAdministracion. */
    private const NIVEL = [
        'estado' => 'Administración del Estado',
        'autonomica' => 'Administración Autonómica',
        'local' => 'Administración Local',
        'justicia' => 'Administración de Justicia',
        'universidades' => 'Universidades',
        'otros' => 'Otras Instituciones',
    ];

    /** Clave UI => tipo de ámbito del paso 2. */
    /*
     * `estado` no lleva filtro intermedio: los cuerpos AGE (Casa Real, AEPD,
     * ministerios…) no tienen sub-organismos y se eligen directamente en el
     * autocompletar de Organismo, que con el paso 2 oculto pasa a numerarse
     * como paso 2 (vía el contador CSS). El resto de niveles territoriales
     * filtran por comunidad autónoma.
     */
    private const FACET = [
        'estado' => self::FACET_NONE,
        'autonomica' => self::FACET_COMUNIDAD,
        'local' => self::FACET_COMUNIDAD,
        'justicia' => self::FACET_COMUNIDAD,
        'universidades' => self::FACET_NONE,
        'otros' => self::FACET_NONE,
    ];

    /** @return array<string, string> clave UI => etiqueta, en orden */
    public static function choices(): array
    {
        return self::LABELS;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::NIVEL[$key]);
    }

    public static function nivelFor(string $key): ?string
    {
        return self::NIVEL[$key] ?? null;
    }

    public static function facetType(string $key): string
    {
        return self::FACET[$key] ?? self::FACET_NONE;
    }

    public static function includesAgeBodies(string $key): bool
    {
        return $key === 'estado';
    }
}
