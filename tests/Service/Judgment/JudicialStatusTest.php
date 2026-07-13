<?php

declare(strict_types=1);

namespace App\Tests\Service\Judgment;

use App\Entity\Judgment;
use App\Service\Judgment\JudicialStatus;
use PHPUnit\Framework\TestCase;

/**
 * The single classifier behind the agent's warning, the banner and the sidebar. What it pins:
 * an annulment HAS A DIRECTION, and the three surfaces must never disagree about it.
 */
final class JudicialStatusTest extends TestCase
{
    private function judgment(
        string $reference,
        string $instance = Judgment::INSTANCE_FIRST,
        bool $final = false,
        ?string $effect = null,
        ?string $stance = null,
        ?string $date = null,
    ): Judgment {
        $judgment = (new Judgment())
            ->setReferenceNumber($reference)
            ->setCourt(Judgment::COURT_AN)
            ->setJudgmentNumber('1/2020')
            ->setInstance($instance)
            ->setIsFinal($final)
            ->setResolutionEffect($effect)
            ->setTransparencyStance($stance);

        if ($date !== null) {
            $judgment->setJudgmentDate(new \DateTimeImmutable($date));
        }

        return $judgment;
    }

    public function testAnUnchallengedResolutionIsSilent(): void
    {
        $status = JudicialStatus::of([]);

        self::assertSame(JudicialStatus::NOT_CHALLENGED, $status->code);
        self::assertFalse($status->isChallenged());
        self::assertSame('', $status->badgeLabel);
    }

    /**
     * BOSCO. The Supreme Court annulled the CTBG resolution because the Council had denied too
     * much, and ordered the source code handed over. Presenting that as a red "annulled, do not
     * cite" would bury the citizen's win — the badge must read as the victory it is.
     */
    public function testAProAccessAnnulmentReadsAsAWinNotAWarning(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('TS/3826/2025', Judgment::INSTANCE_CASSATION, final: true, effect: Judgment::EFFECT_ANULA, stance: Judgment::STANCE_PRO_ACCESS),
        ]);

        self::assertSame(JudicialStatus::ANNULLED_PRO_ACCESS, $status->code);
        self::assertTrue($status->isAnnulled());
        self::assertSame('badge-success', $status->badgeClass);
        self::assertStringContainsString('amplió el acceso', $status->title);
        self::assertStringContainsString('más acceso', $status->hint);
    }

    public function testAnAntiAccessAnnulmentKillsTheResolutionAsPrecedent(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('AN/63/2016', Judgment::INSTANCE_APPEAL, final: true, effect: Judgment::EFFECT_ANULA, stance: Judgment::STANCE_ANTI_ACCESS),
        ]);

        self::assertSame(JudicialStatus::ANNULLED_AGAINST_ACCESS, $status->code);
        self::assertTrue($status->isAnnulled());
        self::assertSame('badge-danger', $status->badgeClass);
        self::assertStringContainsString('no está en vigor', $status->detail);
    }

    public function testAnnulmentBeatsAnEarlierConfirmation(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('JCCA1/10/2016', Judgment::INSTANCE_FIRST, final: true, effect: Judgment::EFFECT_CONFIRMA),
            $this->judgment('TS/99/2017', Judgment::INSTANCE_CASSATION, final: true, effect: Judgment::EFFECT_ANULA),
        ]);

        self::assertTrue($status->isAnnulled());
        self::assertSame('TS/99/2017', $status->ruling?->getReferenceNumber());
    }

    public function testConfirmedByAFirmJudgment(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('TS/1547/2017', Judgment::INSTANCE_CASSATION, final: true, effect: Judgment::EFFECT_CONFIRMA),
        ]);

        self::assertSame(JudicialStatus::CONFIRMED, $status->code);
        self::assertSame('badge-success', $status->badgeClass);
    }

    public function testStillInCourt(): void
    {
        $status = JudicialStatus::of([$this->judgment('JCCA3/50/2024')]);

        self::assertSame(JudicialStatus::PENDING, $status->code);
        self::assertFalse($status->hasFinalJudgment());
        self::assertSame('Recurrida', $status->badgeLabel);
    }

    /**
     * A firm judgment whose effect the analyzer could not pin stays PENDING for the drafter —
     * but the courts HAVE spoken. Collapsing the two would tell the agent the case is still open.
     */
    public function testAFirmButUnclassifiedJudgmentIsPendingYetFinal(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('AN/70/2019', Judgment::INSTANCE_APPEAL, final: true, effect: null),
        ]);

        self::assertSame(JudicialStatus::PENDING, $status->code);
        self::assertTrue($status->hasFinalJudgment());
    }

    /**
     * The timeline must tell the story in the order it was litigated. Sorting by `instance`
     * alphabetically would open with the appeal ('apelacion' < 'primera_instancia').
     */
    public function testTheChainComesBackInProceduralOrder(): void
    {
        $status = JudicialStatus::of([
            $this->judgment('TS/3826/2025', Judgment::INSTANCE_CASSATION, final: true, effect: Judgment::EFFECT_ANULA, stance: Judgment::STANCE_PRO_ACCESS, date: '2025-09-11'),
            $this->judgment('AN/51/2022', Judgment::INSTANCE_APPEAL, date: '2022-02-25'),
            $this->judgment('JCCA8/143/2021', Judgment::INSTANCE_FIRST, date: '2021-06-30'),
        ]);

        self::assertSame(
            ['JCCA8/143/2021', 'AN/51/2022', 'TS/3826/2025'],
            array_map(static fn (Judgment $j): string => $j->getReferenceNumber(), $status->judgments),
        );
    }

    /**
     * The listing card path: presentation reconstructed from the denormalized column alone, with
     * no judgments loaded. This is why the direction is part of the code — BOSCO has to come back
     * green here, where there is nothing else to ask.
     */
    public function testACardRebuildsThePresentationFromTheColumnAlone(): void
    {
        $status = JudicialStatus::fromCode(JudicialStatus::ANNULLED_PRO_ACCESS);

        self::assertSame('badge-success', $status->badgeClass);
        self::assertSame('Anulada (firme)', $status->badgeLabel);
        self::assertSame([], $status->judgments);
        self::assertNull($status->effectiveOutcome());
    }

    public function testAnUnknownStoredCodeDegradesToNotChallenged(): void
    {
        // Garbage in the column must never paint a resolution as annulled.
        self::assertSame(JudicialStatus::NOT_CHALLENGED, JudicialStatus::fromCode('vete_a_saber')->code);
        self::assertSame(JudicialStatus::NOT_CHALLENGED, JudicialStatus::fromCode(null)->code);
    }

    /**
     * The filter asks a plain question; the codes it selects are an implementation detail. What
     * matters is that "anulada" catches BOTH directions — a user looking for annulled resolutions
     * wants BOSCO too.
     */
    public function testTheAnnulledFilterCatchesBothDirections(): void
    {
        $codes = JudicialStatus::codesForFilter('anulada');

        self::assertContains(JudicialStatus::ANNULLED_PRO_ACCESS, $codes);
        self::assertContains(JudicialStatus::ANNULLED_AGAINST_ACCESS, $codes);
        self::assertContains(JudicialStatus::PARTIALLY_ANNULLED, $codes);
        self::assertNotContains(JudicialStatus::CONFIRMED, $codes);
    }

    public function testAnUnknownFilterSelectsNothingRatherThanEverything(): void
    {
        self::assertSame([], JudicialStatus::codesForFilter('inventado'));
    }

    public function testTheEffectiveOutcomeFallsBackToTheSummary(): void
    {
        $ruling = $this->judgment('TS/1/2020', Judgment::INSTANCE_CASSATION, final: true, effect: Judgment::EFFECT_CONFIRMA);
        $ruling->setSummary('El tribunal respalda la resolución del Consejo.');

        self::assertSame(
            'El tribunal respalda la resolución del Consejo.',
            JudicialStatus::of([$ruling])->effectiveOutcome(),
        );

        $ruling->setEffectiveOutcome('El ciudadano obtiene el contrato completo.');

        self::assertSame(
            'El ciudadano obtiene el contrato completo.',
            JudicialStatus::of([$ruling])->effectiveOutcome(),
        );
    }
}
