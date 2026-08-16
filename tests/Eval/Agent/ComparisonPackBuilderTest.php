<?php

declare(strict_types=1);

namespace App\Tests\Eval\Agent;

use App\Eval\Agent\AgentEvalCase;
use App\Eval\Agent\AgentRunResult;
use App\Eval\Agent\AgentTurnOutcome;
use App\Eval\Agent\ComparisonPackBuilder;
use PHPUnit\Framework\TestCase;

final class ComparisonPackBuilderTest extends TestCase
{
    private ComparisonPackBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ComparisonPackBuilder();
    }

    /** @param list<string> $followUps */
    private function evalCase(string $id, string $task = 'request', array $followUps = []): AgentEvalCase
    {
        return new AgentEvalCase($id, $task, 'req-' . $id, 'dame los contratos', $followUps, notes: 'caso de prueba');
    }

    /** @param list<string> $tools */
    private function turn(
        string $action,
        array $tools = ['search_resolutions'],
        int $ms = 1000,
        ?string $body = null,
        string $error = '',
    ): AgentTurnOutcome {
        $decision = ['action' => $action, 'conversational_reply' => '<p>respuesta</p>'];
        if ($body !== null) {
            $decision['draft'] = ['body_html' => $body, 'sources' => [['type' => 'resolution', 'reference' => 'R/0155/2021']]];
        }

        return new AgentTurnOutcome(
            userMessage: 'mensaje',
            decision: $error !== '' ? [] : $decision,
            toolCalls: $tools,
            elapsedMs: $ms,
            error: $error,
        );
    }

    /** @param list<AgentTurnOutcome> $turns */
    private function trajectory(string $model, string $role, array $turns): AgentRunResult
    {
        return new AgentRunResult($model, $role, $turns);
    }

    /** Trayectoria estándar: plan y luego escrito. */
    private function planThenDraft(string $model, string $role): AgentRunResult
    {
        return $this->trajectory($model, $role, [
            $this->turn('reply', tools: []),
            $this->turn('generate', body: '<p>cuerpo del escrito</p>'),
        ]);
    }

    /** @return array<string, array{case: AgentEvalCase, teacher: AgentRunResult, student: AgentRunResult}> */
    private function runs(string ...$ids): array
    {
        $runs = [];
        foreach ($ids as $id) {
            $runs[$id] = [
                'case'    => $this->evalCase($id, followUps: ['adelante']),
                'teacher' => $this->planThenDraft('big-teacher', 'teacher'),
                'student' => $this->planThenDraft('small-student', 'student'),
            ];
        }

        return $runs;
    }

    public function testPackNeverLeaksModelIdentity(): void
    {
        $encoded = json_encode($this->builder->build($this->runs('a', 'b', 'c'), 'run-1')['pack'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('big-teacher', $encoded);
        $this->assertStringNotContainsString('small-student', $encoded);
        $this->assertStringNotContainsString('teacher', $encoded);
    }

    public function testKeyMapsEveryCaseBackToItsModel(): void
    {
        $built = $this->builder->build($this->runs('a', 'b'), 'run-1');

        foreach (['a', 'b'] as $id) {
            $roles = [$built['key']['cases'][$id]['A']['role'], $built['key']['cases'][$id]['B']['role']];
            sort($roles);
            $this->assertSame(['student', 'teacher'], $roles);
        }
    }

    /** Repetir la comparación con el mismo dataset tiene que dar el mismo pack. */
    public function testBlindAssignmentIsDeterministic(): void
    {
        $first  = $this->builder->build($this->runs('a', 'b', 'c'), 'run-1');
        $second = $this->builder->build($this->runs('a', 'b', 'c'), 'run-1');

        $this->assertSame($first['key'], $second['key']);
        $this->assertSame($first['pack'], $second['pack']);
    }

    /** El reparto depende del id, así que no siempre gana la misma posición. */
    public function testBlindAssignmentVariesAcrossCases(): void
    {
        $ids = ['caso-uno', 'caso-dos', 'caso-tres', 'caso-cuatro', 'caso-cinco', 'caso-seis'];
        $built = $this->builder->build($this->runs(...$ids), 'run-1');

        $positions = array_map(
            static fn (array $entry): string => $entry['A']['role'] === 'teacher' ? 'A' : 'B',
            $built['key']['cases'],
        );

        $this->assertContains('A', $positions);
        $this->assertContains('B', $positions);
    }

    /**
     * El juez tiene que ver la conversación entera de cada candidata: el plan y
     * el escrito, para poder valorar si el segundo entrega lo que prometía el
     * primero.
     */
    public function testPackCarriesTheWholeTrajectory(): void
    {
        $built = $this->builder->build($this->runs('a'), 'run-1');
        $entry = $built['pack']['cases'][0];

        $this->assertSame(['dame los contratos', 'adelante'], $entry['guion_de_usuario']);
        $this->assertCount(2, $entry['A']['turnos']);
        $this->assertSame('reply', $entry['A']['turnos'][0]['action']);
        $this->assertSame('generate', $entry['A']['turnos'][1]['action']);
        $this->assertTrue($entry['A']['redacto']);
    }

    /** Quedarse preguntando es un resultado legítimo, no un fallo a esconder. */
    public function testTrajectoryThatNeverDraftsIsReportedAsSuch(): void
    {
        $runs = [
            'x' => [
                'case'    => $this->evalCase('x', followUps: ['adelante']),
                'teacher' => $this->planThenDraft('big-teacher', 'teacher'),
                'student' => $this->trajectory('small-student', 'student', [$this->turn('reply', tools: []), $this->turn('reply', tools: [])]),
            ],
        ];

        $built = $this->builder->build($runs, 'run-1');
        $entry = $built['pack']['cases'][0];
        $noDraft = $entry['A']['redacto'] === false ? $entry['A'] : $entry['B'];

        $this->assertFalse($noDraft['redacto']);
        $this->assertCount(2, $noDraft['turnos']);
        $this->assertSame(1, $this->builder->objectiveMetrics($runs)['student']['sin_borrador']);
    }

    public function testFailedRunIsReportedWithoutPretendingItAnswered(): void
    {
        $runs = [
            'x' => [
                'case'    => $this->evalCase('x'),
                'teacher' => $this->planThenDraft('big-teacher', 'teacher'),
                'student' => $this->trajectory('small-student', 'student', [$this->turn('generate', error: 'formato inesperado')]),
            ],
        ];

        $entry = $this->builder->build($runs, 'run-1')['pack']['cases'][0];

        $this->assertSame('formato inesperado', $entry['A']['fallo'] ?? $entry['B']['fallo'] ?? null);
    }

    public function testObjectiveMetricsAggregateAcrossTurns(): void
    {
        $runs = [
            'a' => [
                'case'    => $this->evalCase('a', followUps: ['adelante']),
                'teacher' => $this->trajectory('big-teacher', 'teacher', [
                    $this->turn('reply', tools: ['read_request_documents'], ms: 2000),
                    $this->turn('generate', tools: ['search_resolutions', 'search_criteria'], ms: 4000, body: '<p>escrito</p>'),
                ]),
                'student' => $this->trajectory('small-student', 'student', [
                    $this->turn('reply', tools: [], ms: 500),
                    $this->turn('generate', tools: ['search_resolutions'], ms: 1500, body: '<p>escrito</p>'),
                ]),
            ],
        ];

        $metrics = $this->builder->objectiveMetrics($runs);

        $this->assertSame(3.0, $metrics['teacher']['tool_calls_media'], 'suma los dos turnos');
        $this->assertSame(1.0, $metrics['student']['tool_calls_media']);
        $this->assertSame(6000.0, $metrics['teacher']['ms_mediana'], 'suma la conversación entera');
        $this->assertSame(2000.0, $metrics['student']['ms_mediana']);
        $this->assertSame(0, $metrics['teacher']['sin_borrador']);
    }

    /** La firma del comportamiento es la SECUENCIA de acciones, no la última. */
    public function testDivergentActionSequencesAreFlagged(): void
    {
        $runs = [
            'coinciden' => [
                'case'    => $this->evalCase('coinciden', followUps: ['adelante']),
                'teacher' => $this->planThenDraft('big-teacher', 'teacher'),
                'student' => $this->planThenDraft('small-student', 'student'),
            ],
            'divergen'  => [
                'case'    => $this->evalCase('divergen', followUps: ['adelante']),
                'teacher' => $this->planThenDraft('big-teacher', 'teacher'),
                // Se lanza a redactar en el primer turno en vez de preguntar.
                'student' => $this->trajectory('small-student', 'student', [
                    $this->turn('generate', body: '<p>escrito</p>'),
                    $this->turn('rewrite', body: '<p>escrito v2</p>'),
                ]),
            ],
        ];

        $metrics = $this->builder->objectiveMetrics($runs);

        $this->assertSame(['divergen'], $metrics['secuencias_divergentes']);
        $this->assertArrayHasKey('reply→generate', $metrics['teacher']['secuencias']);
        $this->assertArrayHasKey('generate→rewrite', $metrics['student']['secuencias']);
    }

    /** El juez no debe ver latencias ni conteos: contaminan su valoración del texto. */
    public function testPackCarriesNoPerformanceSignals(): void
    {
        $encoded = json_encode($this->builder->build($this->runs('a'), 'run-1')['pack'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('elapsed', $encoded);
        $this->assertStringNotContainsString('toolCalls', $encoded);
        $this->assertStringNotContainsString('ms_mediana', $encoded);
    }
}
