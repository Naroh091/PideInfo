<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\AccessRequest;

/**
 * The law of the SUBJECT MATTER, pre-injected the same way the applicable transparency law is.
 *
 * Why deterministic and not left to the agent: measured over the 159 real requests in the
 * database, the model cited **art. 118 LCSP from memory in 16 drafts** — it had located the
 * LCSP with `find_law`, never opened it, and quoted the article anyway. It happened to be right,
 * but that is exactly the failure this feature exists to prevent: the umbral del contrato menor
 * has already changed once by reform, and a request that quotes a repealed threshold is a
 * request the administration can dismiss.
 *
 * Telling the model harder does not fix it. Putting the text in front of it does.
 *
 * Keyword matching is crude on purpose: a false positive costs a couple of thousand characters
 * of context, a false negative costs a wrong citation. The agent can always read more.
 */
final class SubjectMatterFramework
{
    private const LCSP = 'BOE-A-2017-12902';
    private const LGS = 'BOE-A-2003-20977';
    private const LAIMA = 'BOE-A-2006-13010';
    private const TREBEP = 'BOE-A-2015-11719';
    private const TRLRHL = 'BOE-A-2004-4214';

    /**
     * The first matching subject wins, so the more specific patterns come first. Article numbers
     * were checked against the real corpus, not remembered.
     *
     * @var list<array{key: string, pattern: string, label: string, norms: array<string, list<string>>, directive: string}>
     */
    private const SUBJECTS = [
        [
            'key' => 'contratacion',
            'pattern' => '/contrato menor|contratos menores|contrataci[óo]n|licitaci[óo]n|adjudicaci[óo]n|pliego|contratista|expediente de contrataci[óo]n/iu',
            'label' => 'Contratación pública',
            'norms' => [
                // 118: qué documentos EXIGE que existan en el expediente de un contrato menor
                //      (informe motivado de necesidad, no fraccionamiento, aprobación del gasto,
                //      factura). Es la lista exacta que hay que pedir.
                // 63:  perfil de contratante — publicidad obligatoria de lo contratado.
                self::LCSP => ['118', '63'],
            ],
            'directive' => <<<'TXT'
                La solicitud versa sobre **contratación pública**. Tienes arriba el texto literal del art. 118 LCSP:
                úsalo para pedir, uno a uno, **los documentos que ese artículo obliga a que existan** en el expediente
                de un contrato menor (informe motivado de necesidad, justificación de que no se altera el objeto para
                eludir los umbrales, aprobación del gasto, factura). Pedir "copia del expediente" a bulto es mucho más
                fácil de denegar que pedir documentos que la ley obliga a tener.
                El art. 63 (perfil de contratante) sirve para rebatir una eventual denegación por confidencialidad:
                lo que la ley obliga a publicar no puede ser secreto.
                TXT,
        ],
        [
            'key' => 'subvenciones',
            'pattern' => '/subvenci[óo]n|subvenciones|ayuda p[úu]blica|ayudas p[úu]blicas|beneficiario de la ayuda/iu',
            'label' => 'Subvenciones y ayudas públicas',
            'norms' => [
                self::LGS => ['18', '20'],   // publicidad de las subvenciones · BDNS
            ],
            'directive' => <<<'TXT'
                La solicitud versa sobre **subvenciones**. Los arts. 18 y 20 LGS (texto arriba) imponen la publicidad de
                las subvenciones concedidas y su remisión a la Base de Datos Nacional de Subvenciones: lo que ya es de
                publicidad obligatoria no puede denegarse por confidencialidad ni por protección de datos del
                beneficiario cuando este es una persona jurídica.
                TXT,
        ],
        [
            'key' => 'medio_ambiente',
            'pattern' => '/medio ambiente|ambiental|emisiones|contaminaci[óo]n|vertido|residuos|impacto ambiental|calidad del aire/iu',
            'label' => 'Información ambiental',
            'norms' => [
                self::LAIMA => ['10', '13'],   // solicitudes de información ambiental · excepciones
            ],
            'directive' => <<<'TXT'
                La solicitud versa sobre **información ambiental**, que tiene un régimen PROPIO y más favorable que el
                de la Ley 19/2013: la Ley 27/2006 (Convenio de Aarhus). Fundamenta el escrito en ella, no solo en la ley
                de transparencia. Ojo con dos cosas que juegan a favor del solicitante: **no hace falta motivar** la
                solicitud, y las excepciones del art. 13 deben interpretarse de forma **restrictiva**, ponderando en
                cada caso el interés público en la divulgación (y en emisiones al medio ambiente no cabe invocar
                confidencialidad comercial).
                TXT,
        ],
        [
            'key' => 'retribuciones',
            'pattern' => '/retribuci[óo]n|retribuciones|n[óo]mina|sueldo|salario|complemento espec[íi]fico|productividad|relaci[óo]n de puestos/iu',
            'label' => 'Retribuciones y personal público',
            'norms' => [
                self::TREBEP => ['22', '24'],   // retribuciones de los funcionarios · complementarias
            ],
            'directive' => <<<'TXT'
                La solicitud versa sobre **retribuciones de empleados públicos**. Las retribuciones son un concepto
                REGLADO (arts. 22 y 24 TREBEP, texto arriba): están fijadas por norma y presupuesto, de modo que su
                cuantía no es un dato personal íntimo sino la aplicación de una norma pública. Es el argumento central
                frente a la denegación por protección de datos (art. 15 de la ley de transparencia), que debe resolverse
                ponderando, no denegando de plano.
                TXT,
        ],
        [
            'key' => 'presupuestos',
            'pattern' => '/presupuest|cuenta general|ordenanza fiscal|liquidaci[óo]n del presupuesto|gasto p[úu]blico/iu',
            'label' => 'Presupuestos y haciendas locales',
            'norms' => [
                self::TRLRHL => ['169', '212'],   // publicidad del presupuesto · cuenta general
            ],
            'directive' => <<<'TXT'
                La solicitud versa sobre **presupuestos o cuentas públicas**. El TRLRHL impone su exposición pública y su
                aprobación por el Pleno: son documentos de publicidad obligatoria, lo que desactiva de raíz cualquier
                denegación por confidencialidad.
                TXT,
        ],
    ];

    /**
     * @return array{key: string, label: string, norms: array<string, list<string>>, directive: string}|null
     */
    public static function detect(AccessRequest $request): ?array
    {
        $haystack = ($request->getTitle() ?? '') . ' ' . ($request->getDescription() ?? '');

        foreach (self::SUBJECTS as $subject) {
            if (preg_match($subject['pattern'], $haystack) === 1) {
                return [
                    'key' => $subject['key'],
                    'label' => $subject['label'],
                    'norms' => $subject['norms'],
                    'directive' => $subject['directive'],
                ];
            }
        }

        return null;
    }

    /** @return list<array{key: string, label: string, norms: array<string, list<string>>, directive: string}> */
    public static function all(): array
    {
        return array_map(
            static fn (array $s): array => [
                'key' => $s['key'],
                'label' => $s['label'],
                'norms' => $s['norms'],
                'directive' => $s['directive'],
            ],
            self::SUBJECTS,
        );
    }
}
