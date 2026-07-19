<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\HomeHeroExperiment;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pure-logic coverage of the home-hero experiment in its inline (no client key)
 * mode: catalogue integrity, deterministic per-visitor bucketing, spread across
 * arms and the exposure payload handed to GA4.
 */
final class HomeHeroExperimentTest extends TestCase
{
    private function experiment(): HomeHeroExperiment
    {
        // Empty client key => baked-in inline experiment (no network).
        return new HomeHeroExperiment('', 'https://cdn.growthbook.io', new ArrayAdapter(), new NullLogger());
    }

    private function requestWithVisitor(?string $visitorId): Request
    {
        $cookies = $visitorId === null ? [] : [HomeHeroExperiment::VISITOR_COOKIE => $visitorId];

        return new Request([], [], [], $cookies);
    }

    public function testCatalogueHasControlPlusSevenQuestionsAllComplete(): void
    {
        $variants = HomeHeroExperiment::VARIANTS;

        self::assertCount(8, $variants, 'control + 7 question arms');
        self::assertArrayHasKey('control', $variants);

        foreach ($variants as $key => $copy) {
            foreach (['eyebrow', 'titlePre', 'titleMark', 'titlePost', 'subtitle'] as $field) {
                self::assertArrayHasKey($field, $copy, "$key.$field present");
            }
            self::assertNotSame('', trim($copy['eyebrow']), "$key.eyebrow non-empty");
            self::assertNotSame('', trim($copy['titleMark']), "$key.titleMark non-empty (marker text)");
            self::assertNotSame('', trim($copy['subtitle']), "$key.subtitle non-empty");
        }
    }

    public function testAssignmentIsDeterministicPerVisitor(): void
    {
        $exp = $this->experiment();
        $request = $this->requestWithVisitor('fixed-visitor-123');

        $first = $exp->assign($request)->variant['key'];
        $second = $exp->assign($request)->variant['key'];

        self::assertSame($first, $second);
    }

    public function testNewVisitorGetsFreshIdAndExposurePayload(): void
    {
        $assignment = $this->experiment()->assign($this->requestWithVisitor(null));

        self::assertTrue($assignment->newVisitor);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $assignment->visitorId);

        // Inline mode has full coverage, so a new visitor is always in-experiment.
        self::assertNotNull($assignment->tracking);
        self::assertSame(HomeHeroExperiment::EXPERIMENT_KEY, $assignment->tracking['experimentId']);
        self::assertSame($assignment->variant['key'], $assignment->tracking['variationKey']);
        self::assertIsInt($assignment->tracking['variationId']);
    }

    public function testReturnedVariantMatchesCatalogue(): void
    {
        $variant = $this->experiment()->assign($this->requestWithVisitor('visitor-abc'))->variant;
        $key = $variant['key'];

        self::assertArrayHasKey($key, HomeHeroExperiment::VARIANTS);
        self::assertSame(['key' => $key] + HomeHeroExperiment::VARIANTS[$key], $variant);
    }

    public function testExistingVisitorCookieIsNotRegenerated(): void
    {
        $assignment = $this->experiment()->assign($this->requestWithVisitor('keep-me'));

        self::assertFalse($assignment->newVisitor);
        self::assertSame('keep-me', $assignment->visitorId);
    }

    public function testBucketingSpreadsAcrossMultipleArms(): void
    {
        $exp = $this->experiment();
        $seen = [];

        for ($i = 0; $i < 200; ++$i) {
            $seen[$exp->assign($this->requestWithVisitor("visitor-$i"))->variant['key']] = true;
        }

        // Equal-weight 8-arm split over 200 deterministic ids must touch several arms.
        self::assertGreaterThan(1, count($seen), 'bucketing is not degenerate');
    }
}
