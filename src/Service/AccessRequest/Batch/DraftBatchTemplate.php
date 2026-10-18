<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Batch;

/**
 * The unified template of a multi-recipient batch ("plantilla común"). Always
 * shaped as asunto + EXPONE + SOLICITA, whatever the recipients' channels: each
 * per-organism version is later adapted to its own channel shape
 * ({@see BatchVersionGenerator}).
 */
final class DraftBatchTemplate
{
    /**
     * @param list<array<string, mixed>> $sources
     */
    public function __construct(
        public readonly string $batchId,
        public readonly string $title = '',
        public readonly string $expone = '',
        public readonly string $solicita = '',
        public readonly array $sources = [],
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->title) === '' && trim($this->expone) === '' && trim($this->solicita) === '';
    }

    /**
     * Identity of the template text. Stored on each version when it is
     * generated, so a later edit of the template marks that version as outdated.
     */
    public function fingerprint(): string
    {
        return sha1($this->title . "\x1f" . $this->expone . "\x1f" . $this->solicita);
    }

    public function fullText(): string
    {
        return $this->title . "\n" . $this->expone . "\n" . $this->solicita;
    }
}
