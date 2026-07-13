<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * The capacity in which the right of access is exercised.
 *
 * This is not a cosmetic label: it changes the applicable legal regime. A concejal does not
 * file a Ley 19/2013 request — they exercise the art. 77 LBRL / arts. 14-16 ROF right, which
 * carries a *better* deal (positive silence in five days). Getting this wrong means drafting
 * the weaker request.
 *
 * Static catalogue as a final class with consts, per the convention of the repo
 * (see RegAdministrationLevel).
 */
final class RequesterCapacity
{
    public const CITIZEN = 'ciudadano';
    public const ELECTED_OFFICIAL = 'cargo_electo';
    public const JOURNALIST = 'periodista';
    public const RESEARCHER = 'investigador';
    public const ORGANISATION = 'representante_entidad';

    public const DEFAULT = self::CITIZEN;

    /** @var array<string, string> */
    private const LABELS = [
        self::CITIZEN => 'A título particular (ciudadano/a)',
        self::ELECTED_OFFICIAL => 'Concejal/a o cargo electo',
        self::JOURNALIST => 'Periodista',
        self::RESEARCHER => 'Investigador/a o personal académico',
        self::ORGANISATION => 'Representante de una entidad u organización',
    ];

    /**
     * Capacities whose detail must be spelled out, because it goes literally into the heading
     * of the written request ("Concejal del Ayuntamiento de Getafe, Grupo Municipal X").
     *
     * @var list<string>
     */
    private const NEEDS_DETAIL = [self::ELECTED_OFFICIAL, self::ORGANISATION];

    /** @return array<string, string> label => key, ready for a ChoiceType */
    public static function choices(): array
    {
        return array_flip(self::LABELS);
    }

    public static function isValid(string $capacity): bool
    {
        return isset(self::LABELS[$capacity]);
    }

    public static function label(string $capacity): string
    {
        return self::LABELS[$capacity] ?? self::LABELS[self::DEFAULT];
    }

    public static function needsDetail(string $capacity): bool
    {
        return in_array($capacity, self::NEEDS_DETAIL, true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }
}
