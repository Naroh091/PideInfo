<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Service\AI\Llm\ContentPart;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Converts the files attached to a chat turn into the multimodal ContentParts
 * the LLM accepts. Files are not persisted: the parser is a pure transform
 * from UploadedFile to ContentPart and never touches disk after reading.
 */
final class ChatAttachmentParser
{
    public const MAX_FILE_BYTES = 4 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 5 * 1024 * 1024;
    public const MAX_TEXT_CHARS = 200_000;

    private const ALLOWED_MIME = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/jpg',
        'text/csv',
        'text/plain',
        'text/markdown',
        'application/csv',
    ];

    private const TEXT_MIME_PREFIX = ['text/', 'application/csv'];

    /**
     * @param array<UploadedFile|null> $files
     * @return list<ContentPart>
     * @throws \InvalidArgumentException when a file fails validation.
     */
    public function parse(array $files): array
    {
        $parts = [];
        $total = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                throw new \InvalidArgumentException(sprintf(
                    'El archivo «%s» no se pudo leer correctamente.',
                    $file->getClientOriginalName() ?: 'sin nombre',
                ));
            }

            $size = $file->getSize() ?: 0;
            if ($size > self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException(sprintf(
                    'El archivo «%s» supera el límite de %d MB.',
                    $file->getClientOriginalName(),
                    (int) (self::MAX_FILE_BYTES / 1024 / 1024),
                ));
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException(sprintf(
                    'El conjunto de adjuntos supera el límite de %d MB.',
                    (int) (self::MAX_TOTAL_BYTES / 1024 / 1024),
                ));
            }

            $mime = strtolower((string) ($file->getMimeType() ?? 'application/octet-stream'));
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Formato no soportado en el chat: «%s» (%s). Aceptamos PDF, imagen, CSV, TXT o Markdown.',
                    $file->getClientOriginalName(),
                    $mime,
                ));
            }

            $contents = (string) file_get_contents($file->getPathname());
            $filename = $file->getClientOriginalName() ?: 'archivo';

            if ($this->isTextLike($mime)) {
                $text = $contents;
                if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
                    $text = mb_substr($text, 0, self::MAX_TEXT_CHARS)
                        . "\n[... contenido truncado: solo enviamos los primeros "
                        . number_format(self::MAX_TEXT_CHARS) . " caracteres ...]";
                }
                $parts[] = ContentPart::text(sprintf(
                    "=== Adjunto: %s (%s) ===\n%s\n=== Fin de %s ===",
                    $filename,
                    $mime,
                    $text,
                    $filename,
                ));
                continue;
            }

            $parts[] = ContentPart::inlineData($mime, base64_encode($contents));
        }

        return $parts;
    }

    private function isTextLike(string $mime): bool
    {
        foreach (self::TEXT_MIME_PREFIX as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
