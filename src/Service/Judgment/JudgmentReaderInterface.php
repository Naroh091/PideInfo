<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\DTO\JudgmentData;

/**
 * A source of judgments. The interface Resolution readers never had — here from day one so
 * the CENDOJ scraper (browser-driven, fragile, rate-limited) can be added later and switched
 * off without touching the pipeline.
 */
interface JudgmentReaderInterface
{
    /**
     * @return list<JudgmentData> in an order that satisfies reviewedReferenceNumber (a
     *                            judgment always appears after the one it reviews)
     */
    public function fetchAll(?int $limit = null): array;

    /** Judgment::SOURCE_* constant this reader feeds. */
    public function getSource(): string;
}
