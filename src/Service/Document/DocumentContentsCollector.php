<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\AccessRequest;
use App\Entity\Document;

/**
 * Collects extracted text from the documents attached to an AccessRequest, formatted for LLM
 * prompts. Centralises the truncation policy so generator and analyzer share it.
 */
final class DocumentContentsCollector
{
    public const DEFAULT_MAX_CHARS_PER_DOCUMENT = 12000;

    private const TRUNCATION_MARKER = "\n\n[…contenido truncado por longitud]";

    /**
     * @param string[]|null $documentIds When null, includes every document with extracted text.
     *                                   When provided, includes only the documents whose UUID
     *                                   string matches one in the list.
     *
     * @return array<int, array{name: string, type: string, content: string}>
     */
    public function collect(
        AccessRequest $request,
        ?array $documentIds = null,
        int $maxCharsPerDocument = self::DEFAULT_MAX_CHARS_PER_DOCUMENT,
    ): array {
        if ($documentIds === []) {
            return [];
        }

        $contents = [];
        foreach ($request->getDocuments() as $document) {
            if ($documentIds !== null && !in_array((string) $document->getId(), $documentIds, true)) {
                continue;
            }

            $text = $document->getExtractedText();
            if ($text === null || $text === '') {
                continue;
            }

            $contents[] = [
                'name' => $document->getOriginalFilename(),
                'type' => $this->labelFor($document),
                'content' => $this->truncate($text, $maxCharsPerDocument),
            ];
        }

        return $contents;
    }

    private function labelFor(Document $document): string
    {
        $type = $document->getType();
        return method_exists($type, 'label') ? $type->label() : $type->value;
    }

    private function truncate(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . self::TRUNCATION_MARKER;
    }
}
