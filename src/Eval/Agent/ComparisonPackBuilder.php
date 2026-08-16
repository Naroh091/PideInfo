<?php

declare(strict_types=1);

namespace App\Eval\Agent;

/**
 * Convierte las trayectorias de ejecutar cada caso con dos modelos en un "pack"
 * listo para que lo juzgue un LLM.
 *
 * Tres decisiones de método que importan más que el código:
 *
 * 1. **El pack va CIEGO.** Los jueces LLM tienen sesgo de identidad (puntúan
 *    mejor lo que creen que viene del modelo grande) y sesgo de posición
 *    (favorecen sistemáticamente a la primera opción). El pack solo contiene
 *    `A` y `B`, y qué modelo es cada uno vive en un fichero de clave aparte.
 * 2. **La asignación A/B es determinista** (hash del id del caso), no aleatoria:
 *    repetir la comparación con el mismo dataset da exactamente el mismo pack.
 *    Como el reparto depende del id, unos casos ponen al teacher primero y otros
 *    al student, que es lo que neutraliza el sesgo de posición.
 * 3. **Se juzga la CONVERSACIÓN, no solo el escrito.** Qué pregunta el agente y
 *    qué plan propone son parte de la tarea, y el escrito final debe entregar lo
 *    que ese plan prometía. Cada candidata llega al juez como su trayectoria
 *    entera, con cada modelo siguiendo su propia rama.
 */
final class ComparisonPackBuilder
{
    /**
     * Criterios del juez. Separan DELIBERADAMENTE la corrección jurídica de la
     * forma: un modelo que formatea impecablemente y cita mal saca buena nota si
     * se agrega todo en un único número, y ése es justo el fallo peligroso.
     *
     * @var array<string, string>
     */
    public const CRITERIA = [
        'correccion_juridica' => 'La ley invocada, el órgano competente y los artículos citados son los correctos para este caso. Penaliza MUY duro cualquier referencia inventada o mal atribuida (número de artículo, resolución, criterio o sentencia).',
        'calidad_preguntas'   => 'En los turnos en que la candidata pregunta o propone un plan: ¿pregunta lo que de verdad condiciona el escrito, o pide datos irrelevantes? ¿identifica TODOS los argumentos de la Administración que hay que rebatir, o se deja alguno? Preguntar cuando falta un dato decisivo es MEJOR que redactar a ciegas; preguntar lo obvio o lo que ya está en el expediente es peor.',
        'coherencia'          => 'El escrito final entrega lo que la propia candidata anunció en su plan o en sus preguntas. Un plan excelente seguido de un escrito que lo ignora vale poco.',
        'fundamentacion'      => 'Los argumentos se apoyan en doctrina concreta y pertinente. Citar de más sin que venga a cuento penaliza; decir con franqueza que no se ha encontrado doctrina aplicable NO penaliza.',
        'delimitacion'        => 'Lo que se pide está delimitado de forma precisa y accionable por un funcionario: objeto, periodo, formato. Nada de peticiones genéricas o inabarcables.',
        'anticipacion'        => 'Anticipa las causas de inadmisión y los límites previsibles (art. 18, art. 14, ponderación del 15.3, disociación del 16) en vez de dejar que la Administración los use sin réplica.',
        'forma'               => 'Registro administrativo correcto, estructura clara, sin relleno. Obedece las instrucciones de estilo del usuario si las hubo.',
    ];

    /**
     * @param array<string, array{case: AgentEvalCase, teacher: AgentRunResult, student: AgentRunResult}> $runs
     * @return array{pack: array<string, mixed>, key: array<string, mixed>}
     */
    public function build(array $runs, string $runName): array
    {
        $entries = [];
        $key     = [];

        foreach ($runs as $caseId => $run) {
            $teacherIsA = $this->teacherGoesFirst($caseId);
            $a = $teacherIsA ? $run['teacher'] : $run['student'];
            $b = $teacherIsA ? $run['student'] : $run['teacher'];

            $entries[] = [
                'case_id'          => $caseId,
                'task'             => $run['case']->task,
                'notes'            => $run['case']->notes,
                // El guion del usuario es IDÉNTICO para las dos candidatas: se
                // enseña una sola vez para que quede claro que ninguna recibió
                // información que la otra no tuviera.
                'guion_de_usuario' => $run['case']->userMessages(),
                'historial_previo' => array_map(
                    static fn (array $t): array => ['role' => $t['role'] ?? 'user', 'content' => $t['content'] ?? ''],
                    $run['case']->history,
                ),
                'A'                => $this->blindTrajectory($a),
                'B'                => $this->blindTrajectory($b),
            ];

            $key[$caseId] = [
                'A' => ['model' => $a->modelName, 'role' => $a->modelRole],
                'B' => ['model' => $b->modelName, 'role' => $b->modelRole],
            ];
        }

        return [
            'pack' => [
                'run'          => $runName,
                'instructions' => $this->judgeInstructions(),
                'criteria'     => self::CRITERIA,
                'cases'        => $entries,
            ],
            'key' => [
                'run'   => $runName,
                'cases' => $key,
            ],
        ];
    }

    /**
     * Métricas objetivas, sin juez: comparan coste y comportamiento, no calidad.
     * Se calculan aparte del pack para que el juez NO las vea — saber que una
     * candidata llamó a más herramientas o tardó más contamina su valoración
     * del texto.
     *
     * @param array<string, array{case: AgentEvalCase, teacher: AgentRunResult, student: AgentRunResult}> $runs
     * @return array<string, mixed>
     */
    public function objectiveMetrics(array $runs): array
    {
        $summary = [];

        foreach (['teacher', 'student'] as $role) {
            $results = array_map(static fn (array $r): AgentRunResult => $r[$role], array_values($runs));
            $ok      = array_values(array_filter($results, static fn (AgentRunResult $r): bool => !$r->failed()));

            $summary[$role] = [
                'model'            => $results[0]->modelName ?? '',
                'casos'            => count($results),
                'fallos'           => count($results) - count($ok),
                // Casos en los que nunca llegó a redactar: se quedó preguntando
                // o planificando durante todo el guion.
                'sin_borrador'     => count(array_filter($ok, static fn (AgentRunResult $r): bool => $r->finalDraftTurn() === null)),
                'tool_calls_media' => $this->mean(array_map(static fn (AgentRunResult $r): float => (float) count($r->allToolCalls()), $ok)),
                'tools_distintas'  => count(array_unique(array_merge(...array_map(static fn (AgentRunResult $r): array => $r->allToolCalls(), $ok)) ?: [])),
                'ms_mediana'       => $this->median(array_map(static fn (AgentRunResult $r): float => (float) $r->totalMs(), $ok)),
                'long_borrador'    => $this->median(array_map(static fn (AgentRunResult $r): float => (float) mb_strlen(strip_tags($r->draftBody())), $ok)),
                'citas_media'      => $this->mean(array_map(static fn (AgentRunResult $r): float => (float) count($r->sourceReferences()), $ok)),
                'secuencias'       => array_count_values(array_map(static fn (AgentRunResult $r): string => implode('→', $r->actionSequence()), $ok)),
            ];
        }

        // Casos donde los modelos ni siquiera coinciden en QUÉ hicieron turno a
        // turno (uno pregunta y el otro redacta, uno planifica dos veces…).
        // Suelen ser los más informativos del dataset.
        $summary['secuencias_divergentes'] = array_values(array_keys(array_filter(
            $runs,
            static fn (array $r): bool => $r['teacher']->actionSequence() !== $r['student']->actionSequence(),
        )));

        return $summary;
    }

    /** @return array<string, mixed> */
    private function blindTrajectory(AgentRunResult $result): array
    {
        if ($result->failed()) {
            return ['fallo' => $result->firstError()];
        }

        $turns = [];
        foreach ($result->turns as $i => $turn) {
            $turns[] = array_filter([
                'turno'                => $i + 1,
                'action'               => $turn->action(),
                'conversational_reply' => $turn->reply(),
                'plan'                 => $turn->decision['plan'] ?? null,
                'draft'                => $turn->decision['draft'] ?? null,
                'fallo'                => $turn->error !== '' ? $turn->error : null,
            ], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '');
        }

        return [
            'turnos'      => $turns,
            'redacto'     => $result->finalDraftTurn() !== null,
        ];
    }

    /**
     * Reparto determinista de posiciones. Con `crc32` el resultado no depende de
     * la plataforma ni del orden en que se ejecutaron los casos.
     */
    private function teacherGoesFirst(string $caseId): bool
    {
        return (crc32($caseId) % 2) === 0;
    }

    private function judgeInstructions(): string
    {
        return <<<'TXT'
        Eres un jurista experto en transparencia y acceso a la información pública en España
        (Ley 19/2013, Ley 27/2006, doctrina de los consejos de transparencia).

        Para cada caso vas a ver el guion de mensajes del usuario y DOS trayectorias candidatas,
        `A` y `B`, producidas por dos modelos distintos. No sabes cuál es cuál y no debes intentar
        adivinarlo: júzgalas solo por su contenido.

        Cada trayectoria es la conversación COMPLETA de esa candidata: lo que respondió en cada
        turno, el plan que propuso si lo hizo, y el escrito final si llegó a redactarlo. Las dos
        recibieron exactamente los mismos mensajes de usuario; lo que cambia es lo que hizo cada una.

        Reglas:
        - Puntúa cada criterio de 1 a 5 para A y para B, por separado.
        - `correccion_juridica` manda sobre todo lo demás. Un escrito bien redactado que cita
          un artículo equivocado, atribuye una resolución a un órgano que no es, o inventa una
          referencia, es PEOR que uno más pobre pero correcto. Dilo explícitamente cuando pase.
        - Juzga la trayectoria entera, no solo el escrito. Preguntar lo que hacía falta antes de
          redactar es una VIRTUD, no una demora. Redactar a ciegas cuando faltaba un dato decisivo
          es un defecto aunque el texto quede bonito.
        - Si una candidata no llegó a redactar, no la descartes: valora si quedarse preguntando
          estaba justificado en este caso o si fue incapacidad para avanzar.
        - No premies la extensión. Un escrito más largo no es mejor por serlo.
        - Si una candidata reconoce con franqueza que no ha encontrado doctrina aplicable, eso
          NO es un defecto: es el comportamiento correcto frente a inventarse una cita.
        - Si las dos son equivalentes, dilo (`ganador: "empate"`). No fuerces una diferencia.

        Devuelve JSON con esta forma:
        {"casos": [{"case_id": "...", "puntuaciones": {"A": {"correccion_juridica": 4, ...},
        "B": {...}}, "ganador": "A"|"B"|"empate", "razon": "...",
        "errores_juridicos": [{"candidata": "A", "que": "..."}]}]}
        TXT;
    }

    /** @param list<float> $values */
    private function mean(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 2);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? round($values[$mid], 2)
            : round(($values[$mid - 1] + $values[$mid]) / 2, 2);
    }
}
