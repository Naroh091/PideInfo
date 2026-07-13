<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\Entity\Judgment;

/**
 * What the courts did to a resolution — classified once, consumed everywhere.
 *
 * The rule this encodes is the reason the judgment corpus exists: **a resolution annulled by a
 * final judgment must never be presented as favourable precedent**. That rule is enforced on four
 * surfaces (the agent's retrieval block, the banner and the sidebar of /resoluciones/{id}, and the
 * cards of the listing), and each of them used to re-derive it on its own — the template included.
 * Several copies of a legal rule are several chances to say the opposite of what a court decided.
 *
 * The subtlety worth keeping: **an annulment has a direction**. In BOSCO the Supreme Court annulled
 * the CTBG resolution because it had denied too much, and ordered the source code handed over. That
 * annulment is the citizen's best weapon, not a warning — a flat "annulled, do not cite" would bury
 * the win. The direction is baked into the CODE (not derived later) precisely so that a listing
 * card, which only ever sees `resolution.judicial_status`, still gets it right.
 *
 * Two ways in:
 * - {@see self::of()} from the judgments themselves — the classifier, and the only writer of truth.
 * - {@see self::fromCode()} from the denormalized column — presentation only, zero queries.
 */
final class JudicialStatus
{
    public const NOT_CHALLENGED = 'no_recurrida';
    public const PENDING = 'recurrida_pendiente';
    public const CONFIRMED = 'confirmada_firme';
    public const ANNULLED_PRO_ACCESS = 'anulada_firme_pro_acceso';
    public const ANNULLED_AGAINST_ACCESS = 'anulada_firme_contra_acceso';
    public const PARTIALLY_ANNULLED = 'anulada_parcial_firme';
    public const REMITTED = 'retrotraida_firme';

    /**
     * What the public listing offers. Each option maps to the codes it selects, so the filter can
     * ask a plain question ("¿anulada?") without the user having to know the direction.
     *
     * @var array<string, list<string>>
     */
    public const FILTERS = [
        'recurrida' => [
            self::PENDING, self::CONFIRMED, self::ANNULLED_PRO_ACCESS,
            self::ANNULLED_AGAINST_ACCESS, self::PARTIALLY_ANNULLED, self::REMITTED,
        ],
        'anulada' => [self::ANNULLED_PRO_ACCESS, self::ANNULLED_AGAINST_ACCESS, self::PARTIALLY_ANNULLED],
        'confirmada' => [self::CONFIRMED],
        'pendiente' => [self::PENDING],
        'no_recurrida' => [self::NOT_CHALLENGED],
    ];

    /**
     * Presentation, keyed by code — the single table behind the banner, the sidebar and the cards.
     *
     * @var array<string, array{title: string, detail: string, badgeLabel: string, badgeClass: string, bannerClass: string, icon: string, hint: string}>
     */
    private const PRESENTATION = [
        self::ANNULLED_PRO_ACCESS => [
            'title' => 'Anulada por sentencia firme — el tribunal amplió el acceso',
            'detail' => 'El tribunal fue MÁS favorable que el Consejo: concedió lo que la resolución había denegado. Cita la sentencia, no la resolución.',
            'badgeLabel' => 'Anulada (firme)',
            'badgeClass' => 'badge-success',
            'bannerClass' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            'icon' => 'scale',
            'hint' => 'El tribunal concedió más acceso que el Consejo.',
        ],
        self::ANNULLED_AGAINST_ACCESS => [
            'title' => 'Anulada por sentencia firme, en contra del acceso',
            'detail' => 'Su doctrina ya no está en vigor: no puede citarse como precedente favorable.',
            'badgeLabel' => 'Anulada (firme)',
            'badgeClass' => 'badge-danger',
            'bannerClass' => 'bg-red-50 text-red-800 border-red-200',
            'icon' => 'shield-x',
            'hint' => 'Su doctrina ya no está en vigor.',
        ],
        self::PARTIALLY_ANNULLED => [
            'title' => 'Anulada parcialmente por sentencia firme',
            'detail' => 'Parte de la resolución cayó en los tribunales. Verifica que lo que quieres citar es la parte que SOBREVIVIÓ.',
            'badgeLabel' => 'Anulada en parte (firme)',
            'badgeClass' => 'badge-warning',
            'bannerClass' => 'bg-amber-50 text-amber-800 border-amber-200',
            'icon' => 'scale',
            'hint' => 'Solo sobrevive parte de su doctrina.',
        ],
        self::CONFIRMED => [
            'title' => 'Confirmada por sentencia firme',
            'detail' => 'Los tribunales han respaldado esta resolución: su valor como precedente es máximo.',
            'badgeLabel' => 'Confirmada (firme)',
            'badgeClass' => 'badge-success',
            'bannerClass' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            'icon' => 'shield-check',
            'hint' => 'Respaldada por los tribunales.',
        ],
        self::REMITTED => [
            'title' => 'Sentencia firme que retrotrae el procedimiento',
            'detail' => 'La resolución no quedó como doctrina estable: el tribunal devolvió el expediente para volver a tramitarlo.',
            'badgeLabel' => 'Retrotraída (firme)',
            'badgeClass' => 'badge-warning',
            'bannerClass' => 'bg-amber-50 text-amber-800 border-amber-200',
            'icon' => 'scale',
            'hint' => 'El procedimiento volvió atrás.',
        ],
        self::PENDING => [
            'title' => 'Recurrida judicialmente',
            'detail' => 'El recurso sigue en los tribunales, sin sentencia firme todavía.',
            'badgeLabel' => 'Recurrida',
            'badgeClass' => 'badge-warning',
            'bannerClass' => 'bg-amber-50 text-amber-800 border-amber-200',
            'icon' => 'scale',
            'hint' => 'Sin sentencia firme todavía.',
        ],
        self::NOT_CHALLENGED => [
            'title' => '',
            'detail' => '',
            'badgeLabel' => '',
            'badgeClass' => 'badge-secondary',
            'bannerClass' => '',
            'icon' => 'scale',
            'hint' => '',
        ],
    ];

    /**
     * @param list<Judgment> $judgments the whole chain, in procedural order — this is the timeline.
     *                                  Empty when built from the denormalized column.
     * @param Judgment|null  $ruling    the judgment that decides the resolution's fate, if any
     */
    private function __construct(
        public readonly string $code,
        public readonly array $judgments = [],
        public readonly ?Judgment $ruling = null,
        public readonly string $title = '',
        public readonly string $detail = '',
        public readonly string $badgeLabel = '',
        public readonly string $badgeClass = 'badge-secondary',
        public readonly string $bannerClass = '',
        public readonly string $icon = 'scale',
        public readonly string $hint = '',
    ) {
    }

    /**
     * The classifier. Everything else in the app reads its verdict; nothing else decides it.
     *
     * @param iterable<Judgment> $judgments
     */
    public static function of(iterable $judgments): self
    {
        $judgments = Judgment::inProceduralOrder($judgments);

        if ($judgments === []) {
            return self::build(self::NOT_CHALLENGED);
        }

        $final = array_values(array_filter($judgments, static fn (Judgment $j): bool => $j->isFinal()));

        if ($final === []) {
            return self::build(self::PENDING, $judgments, self::firstWithEffectiveOutcome($judgments));
        }

        // Worst-effect-wins across the firm judgments: if any of them annulled the resolution,
        // the annulment is what matters — an earlier confirmation was overturned on the way.
        $annulling = self::firstWithEffect($final, Judgment::EFFECT_ANULA);
        if ($annulling !== null) {
            return self::build(
                $annulling->isProAccess() ? self::ANNULLED_PRO_ACCESS : self::ANNULLED_AGAINST_ACCESS,
                $judgments,
                $annulling,
            );
        }

        foreach ([
            Judgment::EFFECT_ANULA_PARCIAL => self::PARTIALLY_ANNULLED,
            Judgment::EFFECT_CONFIRMA => self::CONFIRMED,
            Judgment::EFFECT_RETROTRAE => self::REMITTED,
        ] as $effect => $code) {
            $ruling = self::firstWithEffect($final, $effect);
            if ($ruling !== null) {
                return self::build($code, $judgments, $ruling);
            }
        }

        // A firm judgment exists but the analyzer could not pin its effect. It stays PENDING for
        // whoever wants to cite it (go and check), even though the courts have already spoken —
        // hence hasFinalJudgment(), which is NOT the same as `code !== PENDING`.
        return self::build(self::PENDING, $judgments, self::firstWithEffectiveOutcome($final));
    }

    /**
     * Presentation from the denormalized `resolution.judicial_status` column: no judgments, no
     * queries. This is what a listing card gets — and why the direction lives in the code itself.
     */
    public static function fromCode(?string $code): self
    {
        return self::build(isset(self::PRESENTATION[$code]) ? (string) $code : self::NOT_CHALLENGED);
    }

    /**
     * @param list<Judgment> $judgments
     */
    private static function build(string $code, array $judgments = [], ?Judgment $ruling = null): self
    {
        $p = self::PRESENTATION[$code];

        return new self(
            code: $code,
            judgments: $judgments,
            ruling: $ruling,
            title: $p['title'],
            detail: $p['detail'],
            badgeLabel: $p['badgeLabel'],
            badgeClass: $p['badgeClass'],
            bannerClass: $p['bannerClass'],
            icon: $p['icon'],
            hint: $p['hint'],
        );
    }

    /** @return array<string, string> */
    public static function getFilterLabels(): array
    {
        return [
            'recurrida' => 'Recurrida (cualquier estado)',
            'anulada' => 'Anulada por sentencia firme',
            'confirmada' => 'Confirmada por sentencia firme',
            'pendiente' => 'Recurrida, sin sentencia firme',
            'no_recurrida' => 'No recurrida',
        ];
    }

    /** @return list<string> the codes a filter option selects, or [] when the option is unknown */
    public static function codesForFilter(string $filter): array
    {
        return self::FILTERS[$filter] ?? [];
    }

    public function isChallenged(): bool
    {
        return $this->code !== self::NOT_CHALLENGED;
    }

    public function isAnnulled(): bool
    {
        return in_array($this->code, [self::ANNULLED_PRO_ACCESS, self::ANNULLED_AGAINST_ACCESS], true);
    }

    /**
     * Careful: this is NOT `code !== PENDING`. A chain can carry a firm judgment whose effect the
     * analyzer could not pin down — it stays PENDING (whoever cites it must go and check) but the
     * courts have already had the last word.
     */
    public function hasFinalJudgment(): bool
    {
        foreach ($this->judgments as $judgment) {
            if ($judgment->isFinal()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the decisive ruling MEANS in practice. The procedural label says what happened to the
     * resolution; this says what the citizen actually got. Null when built from the column.
     */
    public function effectiveOutcome(): ?string
    {
        return $this->ruling?->getEffectiveOutcome() ?? $this->ruling?->getSummary();
    }

    /** @param list<Judgment> $judgments */
    private static function firstWithEffect(array $judgments, string $effect): ?Judgment
    {
        foreach ($judgments as $judgment) {
            if ($judgment->getResolutionEffect() === $effect) {
                return $judgment;
            }
        }

        return null;
    }

    /** @param list<Judgment> $judgments */
    private static function firstWithEffectiveOutcome(array $judgments): ?Judgment
    {
        foreach ($judgments as $judgment) {
            if ($judgment->getEffectiveOutcome() !== null) {
                return $judgment;
            }
        }

        return null;
    }
}
