<?php

namespace App\Experiment;

/**
 * Result of assigning a visitor to a home-hero variant.
 *
 * `variant` is the resolved copy block (see {@see HomeHeroExperiment::VARIANTS}).
 * `tracking` is non-null only when the visitor was actually bucketed into the
 * running experiment — it carries the payload forwarded to GA4 as an
 * `experiment_viewed` event. `visitorId` is the anonymous first-party id used
 * for deterministic bucketing; when `newVisitor` is true the caller must persist
 * it on the response cookie so the assignment stays stable across visits.
 */
final readonly class HeroAssignment
{
    /**
     * @param array<string, string>      $variant  keys: key, eyebrow, titlePre, titleMark, titlePost, subtitle
     * @param array{experimentId: string, variationId: int, variationKey: string}|null $tracking
     */
    public function __construct(
        public array $variant,
        public ?array $tracking,
        public string $visitorId,
        public bool $newVisitor,
    ) {
    }
}
