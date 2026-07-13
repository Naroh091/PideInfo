<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Entity\Judgment;
use App\Repository\JudgmentRepository;
use App\Service\AI\JudicialHistoryAnnotator;
use App\Service\Judgment\JudicialStatus;
use PHPUnit\Framework\TestCase;

/**
 * The product rule this pins: a resolution annulled by a final judgment must NEVER be
 * presented as citable, and one confirmed by a firm judgment must say so.
 */
final class JudicialHistoryAnnotatorTest extends TestCase
{
    private function judgment(string $reference, bool $final, ?string $effect): Judgment
    {
        return (new Judgment())
            ->setReferenceNumber($reference)
            ->setCourt(Judgment::COURT_AN)
            ->setJudgmentNumber(explode('/', $reference)[1] . '/' . explode('/', $reference)[2])
            ->setIsFinal($final)
            ->setResolutionEffect($effect);
    }

    private function annotator(): JudicialHistoryAnnotator
    {
        return new JudicialHistoryAnnotator($this->createMock(JudgmentRepository::class));
    }

    public function testAnUnchallengedResolutionGetsNoNoise(): void
    {
        $history = $this->annotator()->history([]);

        self::assertSame(JudicialStatus::NOT_CHALLENGED, $history['status']);
        self::assertSame('', $history['block']);
    }

    public function testAnnulledByFinalJudgmentScreams(): void
    {
        $history = $this->annotator()->history([
            $this->judgment('AN/63/2016', final: true, effect: Judgment::EFFECT_ANULA),
        ]);

        self::assertSame(JudicialStatus::ANNULLED_AGAINST_ACCESS, $history['status']);
        self::assertStringContainsString('ANULADA POR SENTENCIA FIRME', $history['block']);
        self::assertStringContainsString('NO la cites como precedente favorable', $history['block']);
        self::assertStringContainsString('SAN 63/2016', $history['block']);
    }

    public function testAnnulmentBeatsAnEarlierConfirmation(): void
    {
        // Instance confirmed, appeal annulled — both firm in the data. The annulment is the
        // law of the case; presenting the confirmation would be the exact error this exists
        // to prevent.
        $history = $this->annotator()->history([
            $this->judgment('JCCA1/10/2016', final: true, effect: Judgment::EFFECT_CONFIRMA),
            $this->judgment('AN/99/2017', final: true, effect: Judgment::EFFECT_ANULA),
        ]);

        self::assertSame(JudicialStatus::ANNULLED_AGAINST_ACCESS, $history['status']);
    }

    public function testConfirmedByFinalJudgmentBoostsTheCitation(): void
    {
        $history = $this->annotator()->history([
            $this->judgment('TS/1547/2017', final: true, effect: Judgment::EFFECT_CONFIRMA),
        ]);

        self::assertSame(JudicialStatus::CONFIRMED, $history['status']);
        self::assertStringContainsString('CONFIRMADA POR SENTENCIA FIRME', $history['block']);
        self::assertStringContainsString('cita la resolución Y la sentencia', $history['block']);
    }

    public function testPendingLitigationWarns(): void
    {
        $history = $this->annotator()->history([
            $this->judgment('JCCA3/50/2024', final: false, effect: null),
        ]);

        self::assertSame(JudicialStatus::PENDING, $history['status']);
        self::assertStringContainsString('sin sentencia firme', $history['block']);
    }

    public function testAFirmJudgmentWithUnclassifiedEffectIsHonestAboutIt(): void
    {
        // The analyzer could not pin the effect: say there is case law rather than stay silent.
        $history = $this->annotator()->history([
            $this->judgment('AN/70/2019', final: true, effect: null),
        ]);

        self::assertStringContainsString('efecto no está clasificado', $history['block']);
        self::assertStringContainsString('search_judgments', $history['block']);
    }

    public function testAnnotateAttachesPerResolution(): void
    {
        $repository = $this->createMock(JudgmentRepository::class);
        $repository->method('findByResolutionIds')->willReturn([
            'res-1' => [$this->judgment('AN/63/2016', final: true, effect: Judgment::EFFECT_ANULA)],
        ]);

        $annotator = new JudicialHistoryAnnotator($repository);

        $results = $annotator->annotate([
            ['resolutionId' => 'res-1', 'reference' => 'R/0105/2015'],
            ['resolutionId' => 'res-2', 'reference' => 'R/0200/2020'],
        ]);

        self::assertSame(JudicialStatus::ANNULLED_AGAINST_ACCESS, $results[0]['judicialHistory']['status']);
        self::assertSame(JudicialStatus::NOT_CHALLENGED, $results[1]['judicialHistory']['status']);
    }
}
