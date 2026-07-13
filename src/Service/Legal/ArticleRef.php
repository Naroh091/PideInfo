<?php

declare(strict_types=1);

namespace App\Service\Legal;

/**
 * A parsed reference to one article, or to a contiguous range of them.
 *
 * "118"                          → kind=article, from=118, to=118
 * "14-16"                        → kind=article, from=14,  to=16
 * "118 bis"                      → kind=article, from=118, to=118, suffix=bis
 * "disposicion adicional primera"→ kind=additional, from=1, to=1
 */
final readonly class ArticleRef
{
    public function __construct(
        public string $kind,
        public ?int $from,
        public ?int $to,
        public ?string $suffix = null,
    ) {
    }

    public function isRange(): bool
    {
        return $this->from !== null && $this->to !== null && $this->to > $this->from;
    }

    /** How many articles this ref can match — used to enforce the per-call cap. */
    public function span(): int
    {
        if ($this->from === null || $this->to === null) {
            return 1;
        }

        return max(1, $this->to - $this->from + 1);
    }
}
