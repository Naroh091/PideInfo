<?php

declare(strict_types=1);

namespace App\Eval\Agent;

/**
 * Trayectoria completa de UN modelo sobre UN caso: la conversación entera, turno
 * a turno, más las métricas objetivas agregadas.
 *
 * Se guarda la trayectoria y no solo el escrito final porque en este agente el
 * camino ES parte de la tarea: qué preguntó, qué plan propuso y si el escrito
 * final entrega lo que ese plan prometía.
 */
final readonly class AgentRunResult
{
    /** @param list<AgentTurnOutcome> $turns */
    public function __construct(
        public string $modelName,
        public string $modelRole,
        public array $turns = [],
    ) {
    }

    /** Falla si no llegó a completar ningún turno con decisión válida. */
    public function failed(): bool
    {
        foreach ($this->turns as $turn) {
            if (!$turn->failed()) {
                return false;
            }
        }

        return true;
    }

    public function firstError(): string
    {
        foreach ($this->turns as $turn) {
            if ($turn->error !== '') {
                return $turn->error;
            }
        }

        return $this->failed() ? 'el modelo no devolvió ninguna decisión válida' : '';
    }

    /** @return list<string> la secuencia de acciones, que es la firma del comportamiento */
    public function actionSequence(): array
    {
        return array_map(static fn (AgentTurnOutcome $t): string => $t->action(), $this->turns);
    }

    /**
     * Último turno que tocó el canvas. Es el escrito que hay que juzgar: si el
     * modelo nunca redactó, no hay ninguno y eso también es un resultado.
     */
    public function finalDraftTurn(): ?AgentTurnOutcome
    {
        $found = null;
        foreach ($this->turns as $turn) {
            if ($turn->producedDraft()) {
                $found = $turn;
            }
        }

        return $found;
    }

    public function draftBody(): string
    {
        return $this->finalDraftTurn()?->draftBody() ?? '';
    }

    /** @return list<string> */
    public function allToolCalls(): array
    {
        return array_merge(...array_map(static fn (AgentTurnOutcome $t): array => $t->toolCalls, $this->turns)) ?: [];
    }

    public function totalMs(): int
    {
        return array_sum(array_map(static fn (AgentTurnOutcome $t): int => $t->elapsedMs, $this->turns));
    }

    /**
     * Referencias que el modelo DICE haber citado en el escrito final. Que las
     * leyera de verdad se comprueba con las trazas completas, no aquí.
     *
     * @return list<string>
     */
    public function sourceReferences(): array
    {
        $draft = $this->finalDraftTurn()?->decision['draft'] ?? null;
        if (!is_array($draft) || !is_array($draft['sources'] ?? null)) {
            return [];
        }

        $refs = [];
        foreach ($draft['sources'] as $source) {
            $reference = is_array($source) ? trim((string) ($source['reference'] ?? '')) : '';
            if ($reference !== '') {
                $refs[] = $reference;
            }
        }

        return $refs;
    }
}
