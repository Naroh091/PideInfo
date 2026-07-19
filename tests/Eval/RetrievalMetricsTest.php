<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Eval\RetrievalMetrics;
use PHPUnit\Framework\TestCase;

final class RetrievalMetricsTest extends TestCase
{
    public function testRecallAtK(): void
    {
        $relevant = ['a', 'b'];
        $ranked = ['x', 'a', 'y', 'b', 'z'];

        self::assertSame(0.0, RetrievalMetrics::recallAtK($relevant, $ranked, 1));
        self::assertSame(0.5, RetrievalMetrics::recallAtK($relevant, $ranked, 2));
        self::assertSame(0.5, RetrievalMetrics::recallAtK($relevant, $ranked, 3));
        self::assertSame(1.0, RetrievalMetrics::recallAtK($relevant, $ranked, 4));
        self::assertSame(0.0, RetrievalMetrics::recallAtK([], $ranked, 5));
        self::assertSame(0.0, RetrievalMetrics::recallAtK($relevant, [], 5));
    }

    public function testRecallDeduplicatesRelevantSet(): void
    {
        // A duplicated ground-truth id must not deflate the denominator.
        self::assertSame(1.0, RetrievalMetrics::recallAtK(['a', 'a'], ['a'], 1));
    }

    public function testPrecisionAtK(): void
    {
        $relevant = ['a', 'b'];
        $ranked = ['a', 'x', 'b'];

        self::assertSame(1.0, RetrievalMetrics::precisionAtK($relevant, $ranked, 1));
        self::assertSame(0.5, RetrievalMetrics::precisionAtK($relevant, $ranked, 2));
        self::assertEqualsWithDelta(2 / 3, RetrievalMetrics::precisionAtK($relevant, $ranked, 3), 1e-9);
        self::assertSame(0.0, RetrievalMetrics::precisionAtK($relevant, $ranked, 0));
    }

    public function testReciprocalRank(): void
    {
        self::assertSame(1.0, RetrievalMetrics::reciprocalRank(['a'], ['a', 'b']));
        self::assertSame(0.5, RetrievalMetrics::reciprocalRank(['a'], ['b', 'a']));
        self::assertEqualsWithDelta(1 / 3, RetrievalMetrics::reciprocalRank(['z'], ['x', 'y', 'z']), 1e-9);
        self::assertSame(0.0, RetrievalMetrics::reciprocalRank(['a'], ['x', 'y']));
        self::assertSame(0.0, RetrievalMetrics::reciprocalRank([], ['x']));
    }

    public function testNdcgAtK(): void
    {
        // Single relevant doc at rank 2 of 2: DCG = 1/log2(3), IDCG = 1.
        self::assertEqualsWithDelta(1 / log(3, 2), RetrievalMetrics::ndcgAtK(['a'], ['b', 'a'], 2), 1e-9);

        // Perfect ranking → 1.0 regardless of k ≥ |relevant|.
        self::assertEqualsWithDelta(1.0, RetrievalMetrics::ndcgAtK(['a', 'b'], ['a', 'b', 'x'], 3), 1e-9);

        // Nothing found → 0.
        self::assertSame(0.0, RetrievalMetrics::ndcgAtK(['a'], ['x', 'y'], 2));
        self::assertSame(0.0, RetrievalMetrics::ndcgAtK([], ['x'], 2));
        self::assertSame(0.0, RetrievalMetrics::ndcgAtK(['a'], ['a'], 0));
    }

    public function testNdcgIdealCappedByK(): void
    {
        // 3 relevant docs but k=2: ideal has only 2 hits, so finding 2 of 3 in
        // the top-2 is a perfect nDCG@2.
        self::assertEqualsWithDelta(1.0, RetrievalMetrics::ndcgAtK(['a', 'b', 'c'], ['a', 'b'], 2), 1e-9);
    }
}
