<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\ReciprocalRankFusion;
use PHPUnit\Framework\TestCase;

final class ReciprocalRankFusionTest extends TestCase
{
    public function testAgreementBetweenListsOutranksSingleListTops(): void
    {
        // "b" is mid-list in both arms; "a" and "x" each top one arm only.
        // With k=60 the doubly-present doc must win: 2/(60+2) > 1/61 + 0.
        $fused = ReciprocalRankFusion::fuse([
            ['a', 'b', 'c'],
            ['x', 'b', 'y'],
        ]);

        self::assertSame('b', array_key_first($fused));
        self::assertEqualsWithDelta(1 / 62 + 1 / 62, $fused['b'], 1e-12);
        self::assertEqualsWithDelta(1 / 61, $fused['a'], 1e-12);
    }

    public function testSingleListPreservesItsOrder(): void
    {
        $fused = ReciprocalRankFusion::fuse([['a', 'b', 'c']]);

        self::assertSame(['a', 'b', 'c'], array_keys($fused));
    }

    public function testEmptyListsAreIgnored(): void
    {
        // Degraded lexical arm ([]) must leave the dense ranking untouched.
        $fused = ReciprocalRankFusion::fuse([['a', 'b'], []]);

        self::assertSame(['a', 'b'], array_keys($fused));
    }

    public function testNoListsYieldsNothing(): void
    {
        self::assertSame([], ReciprocalRankFusion::fuse([]));
    }

    public function testWeightsBiasTheFusion(): void
    {
        // Equal single-appearance docs at the same rank: the heavier list wins.
        $fused = ReciprocalRankFusion::fuse([['a'], ['b']], 60, [2.0, 1.0]);

        self::assertSame('a', array_key_first($fused));
        self::assertEqualsWithDelta(2 / 61, $fused['a'], 1e-12);
        self::assertEqualsWithDelta(1 / 61, $fused['b'], 1e-12);
    }

    public function testSmallerKSharpensTopRanks(): void
    {
        $fusedDefault = ReciprocalRankFusion::fuse([['a'], ['b', 'a']], 60);
        $fusedSharp = ReciprocalRankFusion::fuse([['a'], ['b', 'a']], 1);

        // With both k values "a" (rank1 + rank2) beats "b" (rank1 once)…
        self::assertSame('a', array_key_first($fusedDefault));
        self::assertSame('a', array_key_first($fusedSharp));
        // …but the margin grows as k shrinks.
        $marginDefault = $fusedDefault['a'] - $fusedDefault['b'];
        $marginSharp = $fusedSharp['a'] - $fusedSharp['b'];
        self::assertGreaterThan($marginDefault, $marginSharp);
    }
}
