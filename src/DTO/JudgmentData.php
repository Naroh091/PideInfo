<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * One judgment as read from a source listing, before any AI analysis.
 *
 * A single recurso row of the CTBG XLSX yields up to THREE of these — first instance, appeal,
 * cassation — chained through `reviewedReferenceNumber` so the persister can wire the
 * reviewedJudgment relation without the reader knowing about entities.
 */
final readonly class JudgmentData
{
    /**
     * @param list<string> $challengedResolutionRefs canonical, e.g. ["R/0105/2015"]
     * @param list<string> $unparsedRefs             raw tokens the ref parser refused to guess about
     */
    public function __construct(
        public string $referenceNumber,
        public string $source,
        public string $court,
        public ?int $courtNumber,
        public string $instance,
        public ?string $judgmentNumber,
        public array $challengedResolutionRefs,
        public array $unparsedRefs,
        public ?string $subject,
        public ?string $appellant,
        public ?string $appellantType,
        public ?string $representation,
        public ?string $sourceUrl,
        public bool $needsBrowser,
        public bool $isFinal,
        public ?\DateTimeImmutable $finalDate,
        public ?string $reviewedReferenceNumber,
        /** @var array<string, mixed> */
        public array $sourceMetadata = [],
    ) {
    }
}
