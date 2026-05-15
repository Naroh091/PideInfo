<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Stateful splitter that consumes the LLM stream byte by byte and produces
 * two output streams: the conversational reply (everything before the marker
 * "===DECISION===") and the JSON decision (everything after, accumulated and
 * parsed at the end).
 *
 * The splitter is tolerant of the marker arriving mid-token — it keeps the
 * tail of the buffer back until enough characters have been seen to
 * disambiguate.
 *
 * @phpstan-type Decision array{
 *     action: string,
 *     draft?: array<string, mixed>
 * }
 */
final class StreamingDecisionSplitter
{
    public const MARKER = '===DECISION===';

    private string $chatBuffer = '';
    private string $jsonBuffer = '';
    private bool $afterMarker = false;
    /** Tail kept back from emitting in case it contains a partial marker. */
    private string $pendingChatTail = '';

    /**
     * @return list<string> Chat tokens that are safe to emit right now.
     */
    public function feed(string $delta): array
    {
        if ($delta === '') {
            return [];
        }

        if ($this->afterMarker) {
            $this->jsonBuffer .= $delta;
            return [];
        }

        $buffer = $this->pendingChatTail . $delta;
        $markerPos = strpos($buffer, self::MARKER);

        if ($markerPos !== false) {
            $beforeMarker = substr($buffer, 0, $markerPos);
            $afterMarker = substr($buffer, $markerPos + strlen(self::MARKER));
            $this->afterMarker = true;
            $this->jsonBuffer .= $afterMarker;
            $this->chatBuffer .= $beforeMarker;
            $this->pendingChatTail = '';

            return $beforeMarker !== '' ? [$beforeMarker] : [];
        }

        // No full marker yet — retain a tail (one char shorter than the marker)
        // so that a marker straddling token boundaries is still detected on
        // the next feed.
        $keepBack = strlen(self::MARKER) - 1;
        if (strlen($buffer) <= $keepBack) {
            $this->pendingChatTail = $buffer;
            return [];
        }

        // Snap the slice point back to a UTF-8 character boundary so we never
        // emit a half-encoded multi-byte char (the SSE layer json_encode-s
        // each chunk; broken UTF-8 makes json_encode return false, dropping
        // the chunk entirely → "día"→"da", "€"→"", etc.). Continuation bytes
        // are 10xxxxxx (0x80–0xBF); shifting the split a few bytes left at
        // most grows pendingChatTail by ≤3 bytes — harmless.
        $splitPos = strlen($buffer) - $keepBack;
        while ($splitPos > 0 && (ord($buffer[$splitPos]) & 0xC0) === 0x80) {
            $splitPos--;
        }

        $emit = substr($buffer, 0, $splitPos);
        $this->pendingChatTail = substr($buffer, $splitPos);
        $this->chatBuffer .= $emit;
        return $emit !== '' ? [$emit] : [];
    }

    /**
     * Call after the LLM stream has finished. Returns any remaining chat
     * tokens that were held back (only when the marker never arrived).
     *
     * @return list<string>
     */
    public function flushChat(): array
    {
        if ($this->afterMarker || $this->pendingChatTail === '') {
            return [];
        }
        $remaining = $this->pendingChatTail;
        $this->chatBuffer .= $remaining;
        $this->pendingChatTail = '';
        return [$remaining];
    }

    /**
     * Parses the JSON decision accumulated after the marker. Returns null
     * when the marker never arrived (model declined to emit a decision).
     *
     * @return array<string, mixed>|null
     * @throws \RuntimeException when the JSON is unparseable.
     */
    public function decision(): ?array
    {
        if (!$this->afterMarker) {
            return null;
        }

        $raw = trim($this->jsonBuffer);
        if ($raw === '') {
            throw new \RuntimeException('Marker ===DECISION=== present but the JSON block is empty.');
        }

        // Models occasionally wrap the JSON in a code fence even when told not to.
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json|JSON)?\s*/', '', $raw);
            $raw = (string) preg_replace('/\s*```$/', '', $raw);
            $raw = trim($raw);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Decisión JSON inválida: ' . json_last_error_msg());
        }
        if (!is_array($decoded) || !isset($decoded['action']) || !is_string($decoded['action'])) {
            throw new \RuntimeException('Decisión JSON sin clave «action».');
        }

        return $decoded;
    }

    public function rawChat(): string
    {
        return $this->chatBuffer;
    }

    public function rawJson(): string
    {
        return $this->jsonBuffer;
    }
}
