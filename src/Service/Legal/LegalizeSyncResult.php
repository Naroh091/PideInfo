<?php

declare(strict_types=1);

namespace App\Service\Legal;

/**
 * What a pull left behind.
 *
 * `changedPaths === null` means "I could not work out what changed" (fresh clone, unknown
 * base revision, git error). It is NOT the same as an empty array, which means "nothing
 * changed". Callers must treat null as "rescan everything" — that is the fail-safe.
 */
final readonly class LegalizeSyncResult
{
    /**
     * @param list<string>|null $changedPaths repo-relative paths added or modified
     * @param list<string>      $deletedPaths repo-relative paths git reports as deleted
     */
    public function __construct(
        public ?string $oldSha,
        public string $newSha,
        public ?array $changedPaths,
        public array $deletedPaths,
        public bool $cloned,
    ) {
    }

    public function needsFullScan(): bool
    {
        return $this->changedPaths === null;
    }

    public function isUpToDate(): bool
    {
        return !$this->cloned && $this->oldSha === $this->newSha;
    }
}
