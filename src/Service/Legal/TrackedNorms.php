<?php

declare(strict_types=1);

namespace App\Service\Legal;

/**
 * The whitelist: the norms whose articulado we extract into `legal_article` and index in
 * Elasticsearch, so `search_legislation` can find a precept nobody named explicitly.
 *
 * Everything else in the BOE stays reachable — `read_law_articles` parses any norm from
 * /var/data/legalize on demand — so this list is about *discovery*, not about coverage.
 * Adding a norm here costs one `app:legalize:index --norm=…` plus a populate; it is meant
 * to grow.
 *
 * Static catalogue as a `final class` with consts, per the convention of the repo
 * (see App\Service\Submission\RegAdministrationLevel). There is no domain YAML.
 *
 * WARNING: three of the autonomous ids are UNVERIFIED (see UNVERIFIED below).
 * `app:legalize:sync-catalog --verify` checks every id against the real corpus and fails
 * loudly with candidates. Do not deploy without it passing.
 */
final class TrackedNorms
{
    /**
     * boeId => [alias, shortLabel]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NORMS = [
        // ── Estatal ──────────────────────────────────────────────────────────────────
        // El art. 105.b CE es el anclaje constitucional del derecho de acceso y el modelo lo
        // cita en casi todos los escritos. Sin la CE aquí lo citaba **de memoria**, que es
        // justo lo que esta funcionalidad existe para impedir.
        'BOE-A-1978-31229' => ['CE', 'Constitución Española'],
        'BOE-A-2013-12887' => ['LTAIBG', 'Ley 19/2013'],   // Transparencia, acceso a la información pública y buen gobierno
        'BOE-A-2015-10565' => ['LPACAP', 'Ley 39/2015'],   // Procedimiento Administrativo Común
        'BOE-A-2015-10566' => ['LRJSP', 'Ley 40/2015'],    // Régimen Jurídico del Sector Público
        'BOE-A-2017-12902' => ['LCSP', 'Ley 9/2017'],      // Contratos del Sector Público (contrato menor: art. 118)
        'BOE-A-1985-5392'  => ['LBRL', 'Ley 7/1985'],      // Bases del Régimen Local (concejales: art. 77)
        'BOE-A-1986-33252' => ['ROF', 'RD 2568/1986'],     // Reglamento de Organización y Funcionamiento (concejales: arts. 14-16)
        'BOE-A-1998-16718' => ['LJCA', 'Ley 29/1998'],     // Jurisdicción Contencioso-administrativa
        'BOE-A-2018-16673' => ['LOPDGDD', 'LO 3/2018'],    // Protección de Datos (límite del art. 15 LTAIBG)
        'BOE-A-2003-20977' => ['LGS', 'Ley 38/2003'],      // General de Subvenciones
        'BOE-A-2006-13010' => ['LAIMA', 'Ley 27/2006'],    // Información ambiental — régimen de acceso propio
        'BOE-A-2015-11719' => ['TREBEP', 'RDLeg 5/2015'],  // Estatuto Básico del Empleado Público (retribuciones)
        'BOE-A-2004-4214'  => ['TRLRHL', 'RDLeg 2/2004'],  // Haciendas Locales (presupuestos)

        // ── Leyes autonómicas de transparencia ───────────────────────────────────────
        'BOE-A-2014-7534'  => ['LTA', 'Ley 1/2014'],        // Andalucía
        'BOE-A-2015-5332'  => ['LTAPA', 'Ley 8/2015'],      // Aragón
        'BOE-A-2018-14293' => ['LTBGGI', 'Ley 8/2018'],     // Asturias
        'BOE-A-2011-7709'  => ['LBABG-IB', 'Ley 4/2011'],   // Illes Balears — ver nota abajo
        'BOE-A-2015-1114'  => ['LTAIPC', 'Ley 12/2014'],    // Canarias
        'BOE-A-2018-5393'  => ['LTAPC', 'Ley 1/2018'],      // Cantabria
        'BOE-A-2017-1373'  => ['LTBGCLM', 'Ley 4/2016'],    // Castilla-La Mancha
        'BOE-A-2015-3281'  => ['LTPCCYL', 'Ley 3/2015'],    // Castilla y León
        'BOE-A-2015-470'   => ['LTC', 'Ley 19/2014'],       // Cataluña
        'BOE-A-2013-6050'  => ['LGAEX', 'Ley 4/2013'],      // Extremadura
        'BOE-A-2016-3190'  => ['LTBGG', 'Ley 1/2016'],      // Galicia
        'BOE-A-2014-9898'  => ['LTBGLR', 'Ley 3/2014'],     // La Rioja
        'BOE-A-2019-10102' => ['LTCM', 'Ley 10/2019'],      // Madrid
        'BOE-A-2015-184'   => ['LTPCM', 'Ley 12/2014'],     // Región de Murcia
        'BOE-A-2018-7642'  => ['LFTAIPBG', 'LF 5/2018'],    // Navarra
        'BOE-A-2022-8187'  => ['LTBGCV', 'Ley 1/2022'],     // Comunitat Valenciana
        // País Vasco: NO está. Su ley de transparencia no figura en la legislación consolidada
        // del BOE de la que se nutre legalize-es (comprobado: ni una sola norma con
        // "transparencia" en el título bajo es-pv). No se inventa un id: para el País Vasco el
        // agente se apoya en la Ley 19/2013 y, si necesita la autonómica, en web_search.
    ];

    /**
     * Ids verified against the real corpus (`sync-catalog --verify`, 12.282 normas), but whose
     * *choice* deserves a second opinion from a lawyer:
     *
     *   - BOE-A-2011-7709 (Illes Balears): the Ley 4/2011 is a "buena administración y buen
     *     gobierno" law, not a transparency law proper. Balears has no homologous statute; this
     *     is the closest norm, not an equivalent one.
     *
     * @var list<string>
     */
    public const NEEDS_LEGAL_REVIEW = [
        'BOE-A-2011-7709',
    ];

    /**
     * Articles pre-injected into the prompt whenever that norm is the applicable
     * transparency law of the request (see LegalFrameworkComposer). Deadlines, limits,
     * inadmission causes and the complaint route — the four things a drafter always needs
     * and the model most often misremembers.
     *
     * Only the state law is covered for now; the autonomous ones renumber these matters and
     * some have positive silence, so each needs its own reviewed subset. Missing entry =>
     * no deterministic block, the agent still has the tools.
     *
     * @var array<string, list<string>>
     */
    private const KEY_ARTICLES = [
        // 12 derecho de acceso · 13 información pública · 14 límites · 15 datos personales ·
        // 17 solicitud · 18 causas de inadmisión · 19 tramitación · 20 resolución (plazo + silencio) ·
        // 21 unidades responsables · 23 recursos · 24 reclamación ante el CTBG
        'BOE-A-2013-12887' => ['12', '13', '14', '15', '17', '18', '19', '20', '21', '23', '24'],
    ];

    /** @return list<string> */
    public static function boeIds(): array
    {
        return array_keys(self::NORMS);
    }

    public static function isTracked(string $boeId): bool
    {
        return isset(self::NORMS[$boeId]);
    }

    /** "LCSP" */
    public static function alias(string $boeId): ?string
    {
        return self::NORMS[$boeId][0] ?? null;
    }

    /** "Ley 9/2017" */
    public static function shortLabel(string $boeId): ?string
    {
        return self::NORMS[$boeId][1] ?? null;
    }

    /** "LCSP" => "BOE-A-2017-12902". Case-insensitive. */
    public static function byAlias(string $alias): ?string
    {
        $needle = mb_strtoupper(trim($alias));

        foreach (self::NORMS as $boeId => [$normAlias]) {
            if (mb_strtoupper($normAlias) === $needle) {
                return $boeId;
            }
        }

        return null;
    }

    /** @return list<string> Article numbers to pre-inject when this norm is the applicable law. */
    public static function keyArticles(string $boeId): array
    {
        return self::KEY_ARTICLES[$boeId] ?? [];
    }

    /** @return array<string, list<string>> */
    public static function allKeyArticles(): array
    {
        return self::KEY_ARTICLES;
    }
}
