<?php

namespace App\Service\Document;

/**
 * Single source of truth for "should we even bother processing this attachment?"
 * Tiny images (< 10 KB) coming through any ingestion endpoint — direct upload,
 * inbound email, agent webhook — are almost always email-signature logos,
 * social-media icons, tracking pixels or similar noise. We drop them on the
 * floor before they hit the AI pipeline so they don't create orphan documents
 * or waste model calls.
 */
final class DocumentIngestionFilter
{
    /** Anything strictly smaller than this is considered too small to matter. */
    public const TINY_IMAGE_THRESHOLD_BYTES = 10 * 1024;

    public static function isTinyImage(?string $mimeType, int $sizeBytes): bool
    {
        if ($mimeType === null || $sizeBytes <= 0) {
            return false;
        }

        return str_starts_with($mimeType, 'image/')
            && $sizeBytes < self::TINY_IMAGE_THRESHOLD_BYTES;
    }
}
