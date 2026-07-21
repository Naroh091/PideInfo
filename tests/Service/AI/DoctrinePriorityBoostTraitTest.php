<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\DoctrinePriorityBoostTrait;
use PHPUnit\Framework\TestCase;

/**
 * The store returns cosine DISTANCE as `score` (lower = better). The boost must
 * therefore SUBTRACT from a priority hit's distance and sort ASCENDING — a
 * moderate nudge that a markedly closer non-priority hit still beats.
 */
final class DoctrinePriorityBoostTraitTest extends TestCase
{
    private object $harness;

    protected function setUp(): void
    {
        $this->harness = new class {
            use DoctrinePriorityBoostTrait;

            /**
             * @param array<int, array<string, mixed>> $rows
             * @param array<int, string>               $ids
             * @return array<int, array<string, mixed>>
             */
            public function run(array $rows, array $ids): array
            {
                return $this->applyDoctrinePriorityBoost($rows, $ids);
            }
        };
    }

    public function testEmptyPrioritySortsAscendingByDistance(): void
    {
        $rows = [
            ['id' => 'far', 'score' => 0.40, 'complaintOrganismId' => 'A'],
            ['id' => 'near', 'score' => 0.10, 'complaintOrganismId' => 'B'],
            ['id' => 'mid', 'score' => 0.25, 'complaintOrganismId' => 'A'],
        ];

        $out = $this->harness->run($rows, []);

        $this->assertSame(['near', 'mid', 'far'], array_column($out, 'id'));
    }

    public function testPriorityHitOvertakesWithinBonusGap(): void
    {
        // Gap 0.02 < bonus 0.03 → the priority hit (garante) jumps ahead.
        $rows = [
            ['id' => 'nonPriorityCloser', 'score' => 0.25, 'complaintOrganismId' => 'OTHER'],
            ['id' => 'priorityFarther', 'score' => 0.27, 'complaintOrganismId' => 'GARANTE'],
        ];

        $out = $this->harness->run($rows, ['GARANTE']);

        $this->assertSame('priorityFarther', $out[0]['id']);
        $this->assertEqualsWithDelta(0.27 - 0.03, $out[0]['adjustedScore'], 1e-9);
    }

    public function testMarkedlyCloserNonPriorityStillWins(): void
    {
        // Gap 0.05 > bonus 0.03 → relevance wins; the boost is moderate, not hard.
        $rows = [
            ['id' => 'priorityFar', 'score' => 0.40, 'complaintOrganismId' => 'GARANTE'],
            ['id' => 'nonPriorityClose', 'score' => 0.35, 'complaintOrganismId' => 'OTHER'],
        ];

        $out = $this->harness->run($rows, ['GARANTE']);

        $this->assertSame('nonPriorityClose', $out[0]['id']);
    }

    public function testRowWithoutOrganismIsNeverBoosted(): void
    {
        $rows = [
            ['id' => 'noOrg', 'score' => 0.31, 'complaintOrganismId' => null],
            ['id' => 'priority', 'score' => 0.33, 'complaintOrganismId' => 'GARANTE'],
        ];

        $out = $this->harness->run($rows, ['GARANTE']);

        // 0.33 - 0.03 = 0.30 < 0.31 → the boosted priority hit leads; noOrg is untouched.
        $this->assertSame('priority', $out[0]['id']);
    }
}
