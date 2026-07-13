<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

/**
 * Splits a long text into embedding-sized chunks at paragraph boundaries.
 *
 * Same algorithm the resolution pipeline uses (ResolutionProcessingTrait::chunkText),
 * extracted so judgments embed with identical chunking — retrieval quality depends on the
 * corpus being chunked consistently.
 */
final class TextChunker
{
    public const DEFAULT_MAX_CHARS = 4000;

    /**
     * @return list<string>
     */
    public function chunk(string $text, int $maxChars = self::DEFAULT_MAX_CHARS): array
    {
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > $maxChars) {
                $chunks[] = trim($current);
                $current = $paragraph;
            } else {
                $current .= ($current !== '' ? "\n\n" : '') . $paragraph;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
