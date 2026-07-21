<?php

declare(strict_types=1);

namespace App\Service\AI\Moderation;

/**
 * Outcome of a single moderation screen. `allowed` is the only field the caller
 * must branch on; `category` and `severity` are recorded for audit
 * (`metadata['anonymous']['moderation'][]`) and to tune thresholds later.
 */
final readonly class ModerationVerdict
{
    /** Categories the moderation model may return; anything else is coerced to `other`. */
    public const CATEGORIES = [
        'clean',
        'off_scope',
        'harmful_content',
        'third_party_pii',
        'jailbreak_injection',
        'other',
    ];

    public function __construct(
        public bool $allowed,
        public string $category = 'clean',
        public string $severity = 'none',
    ) {
    }

    public static function allow(string $category = 'clean'): self
    {
        return new self(true, $category);
    }

    public static function block(string $category, string $severity = 'high'): self
    {
        return new self(false, $category, $severity);
    }
}
