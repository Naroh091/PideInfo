<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Service\Submission\RequesterCapacity;

/**
 * Maps the capacity the right is exercised in to the legal framework that actually governs it.
 *
 * The articles listed here are read LITERALLY from `legal_article` and pasted into the prompt
 * by LegalFrameworkComposer. The point is not to tell the model that a concejal has five days
 * — it is to put the text of art. 14 ROF in front of it so it does not have to remember.
 */
final class CapacityLegalFramework
{
    private const CE = 'BOE-A-1978-31229';
    private const LBRL = 'BOE-A-1985-5392';
    private const ROF = 'BOE-A-1986-33252';
    private const LTAIBG = 'BOE-A-2013-12887';
    private const LPACAP = 'BOE-A-2015-10565';

    /**
     * capacity => ['norms' => boeId => article numbers, 'directives' => text]
     *
     * @var array<string, array{norms: array<string, list<string>>, directives: string}>
     */
    private const FRAMEWORK = [
        RequesterCapacity::ELECTED_OFFICIAL => [
            'norms' => [
                self::CE => ['23'],              // participación política: el ius in officium del cargo electo
                self::LBRL => ['77'],            // derecho de los miembros de la Corporación
                self::ROF => ['14', '15', '16'], // procedimiento, consulta directa, libramiento de copias
            ],
            'directives' => <<<'TXT'
                El usuario ejerce el derecho **en su condición de concejal/a o cargo electo**. Esto NO es una
                solicitud ordinaria de transparencia y redactarla como tal sería un error que le perjudica:

                1. El cauce es el **art. 77 LBRL** junto con los **arts. 14 a 16 del ROF**, no la Ley 19/2013. El
                   régimen es más favorable — tienes su texto literal arriba: úsalo, no lo cites de memoria, y
                   comprueba en él el plazo y el sentido del silencio antes de afirmarlos.
                2. Invoca además el **art. 23.1 CE** (tienes su texto arriba) y su conexión con el *ius in officium*
                   del cargo electo: el acceso a la información es instrumental para el ejercicio del cargo, y su
                   denegación lesiona un derecho fundamental.
                3. **El Reglamento Orgánico Municipal (ROM) del ayuntamiento NO está en el BOE ni en el catálogo de
                   normas.** Si el escrito depende de un plazo o un trámite del ROM, búscalo con `web_search`
                   ("Reglamento Orgánico Municipal {municipio}") y léelo con `scrape_url`. Si NO lo encuentras, dilo
                   explícitamente en el escrito ("de no existir previsión específica en el ROM, rige lo dispuesto en
                   el art. 77 LBRL y los arts. 14 a 16 del ROF") en lugar de inventarte una previsión que no has visto.
                4. Identifica la condición en el encabezamiento del escrito.
                TXT,
        ],

        RequesterCapacity::JOURNALIST => [
            'norms' => [
                self::LTAIBG => ['14'],   // el test de daño y el interés público en la ponderación
            ],
            'directives' => <<<'TXT'
                El usuario ejerce el derecho **como periodista**. El derecho de acceso es el mismo que el de
                cualquier ciudadano — no invoques un régimen especial que no existe —, pero sí cambia la
                **ponderación del art. 14.2**: el interés público en la divulgación pesa más cuando la información
                alimenta el debate público y el control del poder (art. 20.1.d CE, libertad de información).
                Argumenta ese interés público de forma concreta, no genérica.
                TXT,
        ],

        RequesterCapacity::RESEARCHER => [
            'norms' => [
                self::LTAIBG => ['15'],   // datos personales: disociación y acceso parcial
            ],
            'directives' => <<<'TXT'
                El usuario ejerce el derecho **como investigador/a**. Si la información contiene datos personales,
                apóyate en el art. 15 LTAIBG (lo tienes arriba): ofrece expresamente la **disociación** o el acceso
                parcial como alternativa a la denegación, y justifica el fin de investigación. Un ofrecimiento de
                anonimización desactiva la mayoría de las denegaciones por protección de datos.
                TXT,
        ],

        RequesterCapacity::ORGANISATION => [
            'norms' => [
                self::LPACAP => ['5'],    // acreditación de la representación
            ],
            'directives' => <<<'TXT'
                El usuario actúa **en representación de una entidad**. Identifica a la entidad y al representante en
                el encabezamiento, y ten presente el art. 5 LPACAP sobre la acreditación de la representación: si la
                Administración la requiere, se subsana, no se deniega el acceso.
                TXT,
        ],
    ];

    /** @return array<string, list<string>> boeId => article numbers to pre-inject */
    public static function norms(string $capacity): array
    {
        return self::FRAMEWORK[$capacity]['norms'] ?? [];
    }

    public static function directives(string $capacity): ?string
    {
        return self::FRAMEWORK[$capacity]['directives'] ?? null;
    }

    public static function has(string $capacity): bool
    {
        return isset(self::FRAMEWORK[$capacity]);
    }

    /** @return array<string, array{norms: array<string, list<string>>, directives: string}> */
    public static function all(): array
    {
        return self::FRAMEWORK;
    }
}
