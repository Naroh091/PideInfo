<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Service\Legal\TrackedNorms;
use PHPUnit\Framework\TestCase;

/**
 * These assertions fix the *shape* of the whitelist. They cannot tell you an id is the
 * RIGHT norm — only `app:legalize:sync-catalog --verify`, run against the real corpus, can.
 */
final class TrackedNormsTest extends TestCase
{
    public function testEveryIdLooksLikeABoeIdentifier(): void
    {
        foreach (TrackedNorms::boeIds() as $boeId) {
            self::assertMatchesRegularExpression('/^BOE-A-\d{4}-\d+$/', $boeId);
        }
    }

    public function testIdsAndAliasesAreUnique(): void
    {
        $ids = TrackedNorms::boeIds();
        self::assertSame($ids, array_values(array_unique($ids)));

        $aliases = array_map(TrackedNorms::alias(...), $ids);
        self::assertSame($aliases, array_values(array_unique($aliases)), 'Two norms share an alias: byAlias() would be ambiguous.');
    }

    public function testAliasLookupIsBidirectionalAndCaseInsensitive(): void
    {
        self::assertSame('BOE-A-2017-12902', TrackedNorms::byAlias('LCSP'));
        self::assertSame('BOE-A-2017-12902', TrackedNorms::byAlias('lcsp'));
        self::assertSame('LCSP', TrackedNorms::alias('BOE-A-2017-12902'));
        self::assertNull(TrackedNorms::byAlias('no-existe'));
    }

    public function testTheNormsThatResolveTheConcejalCaseAreTracked(): void
    {
        // The 5-day deadline of a concejal lives in arts. 14-16 ROF, not in the LTAIBG.
        self::assertTrue(TrackedNorms::isTracked('BOE-A-1985-5392'), 'LBRL must be tracked');
        self::assertTrue(TrackedNorms::isTracked('BOE-A-1986-33252'), 'ROF must be tracked');
    }

    public function testKeyArticlesOnlyReferenceTrackedNorms(): void
    {
        foreach (array_keys(TrackedNorms::allKeyArticles()) as $boeId) {
            self::assertTrue(
                TrackedNorms::isTracked($boeId),
                sprintf('KEY_ARTICLES references %s, which is not in the whitelist: its articulado would never be extracted.', $boeId),
            );
        }
    }

    public function testNormsFlaggedForLegalReviewAreStillTracked(): void
    {
        foreach (TrackedNorms::NEEDS_LEGAL_REVIEW as $boeId) {
            self::assertTrue(TrackedNorms::isTracked($boeId));
        }
    }
}
