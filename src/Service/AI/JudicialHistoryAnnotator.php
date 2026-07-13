<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\Judgment;
use App\Repository\JudgmentRepository;
use App\Service\Judgment\JudicialStatus;

/**
 * Attaches the judicial history of each retrieved resolution: has it been challenged in
 * court, and what did the courts do to it?
 *
 * This is the product rule the whole judgment corpus exists to enforce: **a resolution
 * annulled by a final judgment must never be cited as favourable precedent** — its doctrine
 * is dead, and citing it hands the Administration a rebuttal for free. Symmetrically, a
 * resolution CONFIRMED by a firm judgment is worth far more than one nobody challenged.
 *
 * The classification lives in {@see JudicialStatus}, shared with the UI. What lives HERE is only
 * the prose the model reads — it is prompt, and it is tuned to be impossible to ignore.
 *
 * Cost: one JOIN query per retrieval, zero LLM calls.
 */
final class JudicialHistoryAnnotator
{
    public function __construct(
        private readonly JudgmentRepository $judgments,
    ) {
    }

    /**
     * Adds a `judicialHistory` key to every result row that carries a `resolutionId`.
     * Rows whose resolution was never challenged get status no_recurrida and an empty block.
     *
     * @param list<array<string, mixed>> $results retriever rows
     *
     * @return list<array<string, mixed>>
     */
    public function annotate(array $results): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $r): ?string => isset($r['resolutionId']) ? (string) $r['resolutionId'] : null,
            $results,
        )));

        if ($ids === []) {
            return $results;
        }

        // One query for the whole page. Never $resolution->getJudgments() here: that inverse
        // side is lazy, and reaching for it per row would fire one query per hit.
        $byResolution = $this->judgments->findByResolutionIds($ids);

        foreach ($results as &$result) {
            $judgments = $byResolution[(string) ($result['resolutionId'] ?? '')] ?? [];
            $result['judicialHistory'] = $this->history($judgments);
        }

        return $results;
    }

    /**
     * @param list<Judgment> $judgments
     *
     * @return array{status: string, block: string}
     */
    public function history(array $judgments): array
    {
        $status = JudicialStatus::of($judgments);

        return ['status' => $status->code, 'block' => self::block($status)];
    }

    /**
     * The block the agent reads. Every branch leads with what the ruling MEANS — the procedural
     * label alone ("anulada") reads as bad news even when the court handed the citizen a win.
     */
    private static function block(JudicialStatus $status): string
    {
        if (!$status->isChallenged()) {
            return '';
        }

        $ruling = $status->ruling;
        $citation = $ruling?->getCitationLabel() ?? self::citations($status);
        $conclusion = $status->effectiveOutcome() !== null ? "\n> " . trim((string) $status->effectiveOutcome()) : '';

        return match (true) {
            $status->code === JudicialStatus::ANNULLED_PRO_ACCESS => sprintf(
                "⚖️ **RESOLUCIÓN ANULADA POR SENTENCIA FIRME — Y EL TRIBUNAL FUE MÁS FAVORABLE AL ACCESO QUE EL CONSEJO** (%s).%s\n"
                . '**Cita la SENTENCIA, no la resolución**: el tribunal concedió lo que el consejo había denegado. '
                . 'Es doctrina de máximo valor a tu favor.',
                $citation,
                $conclusion,
            ),
            $status->code === JudicialStatus::ANNULLED_AGAINST_ACCESS => sprintf(
                "🚫 **RESOLUCIÓN ANULADA POR SENTENCIA FIRME, EN CONTRA DEL ACCESO** (%s).%s\n"
                . '**NO la cites como precedente favorable**: su doctrina ya no está en vigor, y la '
                . 'sentencia que la anuló es la que invocará la Administración. Anticípala y distingue tu caso.',
                $citation,
                $conclusion,
            ),
            $status->code === JudicialStatus::PARTIALLY_ANNULLED => sprintf(
                "⚠️ **RESOLUCIÓN ANULADA PARCIALMENTE por sentencia firme** (%s).%s\n"
                . 'Verifica que la parte que quieres citar es la que SOBREVIVIÓ antes de apoyarte en ella.',
                $citation,
                $conclusion,
            ),
            $status->code === JudicialStatus::CONFIRMED => sprintf(
                "✅ **CONFIRMADA POR SENTENCIA FIRME** (%s).%s\n"
                . 'Su valor como precedente es máximo: cita la resolución Y la sentencia que la confirma.',
                $citation,
                $conclusion,
            ),
            $status->code === JudicialStatus::REMITTED => sprintf(
                '⚠️ Sentencia firme que **retrotrae** el procedimiento (%s): la resolución no quedó '
                . 'como doctrina estable. Cítala solo si no tienes nada mejor, y con esa advertencia.',
                $citation,
            ),
            // A firm judgment exists but the analyzer could not pin its effect. Honesty over
            // silence: say there is case law and let the drafter check it.
            $status->hasFinalJudgment() => sprintf(
                '⚠️ Existe sentencia firme sobre esta resolución (%s) cuyo efecto no está clasificado. '
                . 'Verifícala con search_judgments antes de citarla.',
                self::citations($status),
            ),
            // Still in court. Cite every judgment in the chain: with no decisive ruling to name,
            // the drafter needs to know WHAT is being litigated.
            default => sprintf(
                '⚠️ **RESOLUCIÓN RECURRIDA JUDICIALMENTE, sin sentencia firme todavía** (%s). '
                . 'Puedes citarla, pero advierte de que el recurso sigue en los tribunales.',
                self::citations($status),
            ),
        };
    }

    /** Every judgment in the chain, for the branches with no single decisive ruling to name. */
    private static function citations(JudicialStatus $status): string
    {
        return implode(', ', array_unique(array_map(
            static fn (Judgment $j): string => $j->getCitationLabel(),
            $status->judgments,
        )));
    }
}
