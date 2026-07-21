<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Turns the citations stored in `AccessRequest.metadata['cited_sources']` into
 * numbered footnotes for the generated PDFs: a superscript marker (¹, ²…) placed
 * in the body right after the reference is mentioned, plus a "Fuentes utilizadas"
 * list rendered at the end of the document with the resolvable link.
 *
 * Two entry points share the same numbering/notes logic:
 *   - {@see format()} for plain-text bodies (solicitud → description);
 *   - {@see formatHtml()} for HTML bodies (reclamación / alegaciones → body_html).
 *
 * dompdf cannot anchor a footnote to the physical page where its marker lands
 * (it has no CSS `float: footnote`), so the notes collect at the end of the
 * content flow; for a one-page document — the common case — that reads as
 * page footnotes. See docs/request-workflow.md (sección «Fuentes utilizadas»).
 */
final class CitationFootnoteFormatter
{
    /**
     * Plain-text body → escaped paragraphs with inline markers + notes.
     *
     * @param list<array<string, mixed>> $sources cited_sources: {type, reference, label, url}
     *
     * @return array{paragraphs: list<string>, notes: list<array{num: int, label: string, url: ?string}>}
     */
    public function format(string $bodyText, array $sources): array
    {
        $clean = $this->clean($sources);
        $escaped = htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($clean === []) {
            return ['paragraphs' => $this->toParagraphs($escaped), 'notes' => []];
        }

        // Locate each source in the escaped body (byte offsets for substr()).
        $located = [];
        foreach ($clean as $i => $source) {
            $hit = $this->locateInString($escaped, $source);
            if ($hit !== null) {
                $located[$i] = $hit;
            }
        }
        uasort($located, static fn (array $a, array $b): int => $a['end'] <=> $b['end']);

        [$numberOf, $notes] = $this->numberAndNotes($clean, array_keys($located));

        // Insert markers right-to-left so earlier offsets stay valid.
        $inserts = [];
        foreach ($located as $i => $hit) {
            $inserts[] = ['at' => $hit['end'], 'html' => $this->marker($numberOf[$i])];
        }
        usort($inserts, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        foreach ($inserts as $ins) {
            $escaped = substr($escaped, 0, $ins['at']) . $ins['html'] . substr($escaped, $ins['at']);
        }

        return ['paragraphs' => $this->toParagraphs($escaped), 'notes' => $notes];
    }

    /**
     * HTML body → same HTML with inline markers injected into text runs only
     * (tags untouched, so the markup is preserved byte-for-byte otherwise) + notes.
     *
     * @param list<array<string, mixed>> $sources
     *
     * @return array{html: string, notes: list<array{num: int, label: string, url: ?string}>}
     */
    public function formatHtml(string $bodyHtml, array $sources): array
    {
        $clean = $this->clean($sources);
        if ($clean === []) {
            return ['html' => $bodyHtml, 'notes' => []];
        }

        // Split into alternating text / tag tokens; even indexes are text runs
        // (already HTML-escaped), odd indexes are `<...>` tags we never touch.
        $tokens = preg_split('/(<[^>]+>)/', $bodyHtml, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$bodyHtml];

        // Locate each source in the first text run that mentions it.
        $located = [];   // sourceIndex => ['token' => int, 'end' => int (char offset)]
        foreach ($clean as $i => $source) {
            $hit = $this->locateInTokens($tokens, $source);
            if ($hit !== null) {
                $located[$i] = $hit;
            }
        }
        // Document order = (token index, char offset within the token).
        uasort($located, static function (array $a, array $b): int {
            return [$a['token'], $a['end']] <=> [$b['token'], $b['end']];
        });

        [$numberOf, $notes] = $this->numberAndNotes($clean, array_keys($located));

        // Group inserts by token, then apply right-to-left within each token.
        $byToken = [];
        foreach ($located as $i => $hit) {
            $byToken[$hit['token']][] = ['at' => $hit['end'], 'html' => $this->marker($numberOf[$i])];
        }
        foreach ($byToken as $t => $inserts) {
            usort($inserts, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
            foreach ($inserts as $ins) {
                $tokens[$t] = mb_substr($tokens[$t], 0, $ins['at']) . $ins['html'] . mb_substr($tokens[$t], $ins['at']);
            }
        }

        return ['html' => implode('', $tokens), 'notes' => $notes];
    }

    /**
     * @param list<array<string, mixed>> $sources
     *
     * @return list<array<string, mixed>>
     */
    private function clean(array $sources): array
    {
        return array_values(array_filter($sources, static function ($s): bool {
            return is_array($s) && (($s['label'] ?? '') !== '' || ($s['reference'] ?? '') !== '');
        }));
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{pos: int, end: int}|null Byte offsets into $escaped.
     */
    private function locateInString(string $escaped, array $source): ?array
    {
        foreach ($this->searchKeys($source) as $key) {
            $escapedKey = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $pos = mb_stripos($escaped, $escapedKey);
            if ($pos !== false) {
                $bytePos = strlen(mb_substr($escaped, 0, $pos));
                return ['pos' => $bytePos, 'end' => $bytePos + strlen($escapedKey)];
            }
        }

        return null;
    }

    /**
     * @param list<string>         $tokens
     * @param array<string, mixed> $source
     *
     * @return array{token: int, end: int}|null Char offset (end) within the text token.
     */
    private function locateInTokens(array $tokens, array $source): ?array
    {
        foreach ($this->searchKeys($source) as $key) {
            $escapedKey = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            for ($t = 0, $n = count($tokens); $t < $n; $t += 2) {
                // Even tokens are text runs; a run starting with '<' is a tag we skip.
                if (($tokens[$t][0] ?? '') === '<') {
                    continue;
                }
                $pos = mb_stripos($tokens[$t], $escapedKey);
                if ($pos !== false) {
                    return ['token' => $t, 'end' => $pos + mb_strlen($escapedKey)];
                }
            }
        }

        return null;
    }

    /**
     * Assign footnote numbers (located sources first, in appearance order; then
     * any that couldn't be matched, keeping their original order) and build the
     * ordered notes list.
     *
     * @param list<array<string, mixed>> $clean
     * @param list<int>                  $orderedLocated source indexes, already in appearance order
     *
     * @return array{0: array<int, int>, 1: list<array{num: int, label: string, url: ?string}>}
     */
    private function numberAndNotes(array $clean, array $orderedLocated): array
    {
        $numberOf = [];
        $n = 0;
        foreach ($orderedLocated as $i) {
            $numberOf[$i] = ++$n;
        }
        foreach (array_keys($clean) as $i) {
            if (!isset($numberOf[$i])) {
                $numberOf[$i] = ++$n;
            }
        }

        $notes = [];
        foreach ($clean as $i => $source) {
            $label = (string) ($source['label'] ?? '');
            $notes[$numberOf[$i]] = [
                'num' => $numberOf[$i],
                'label' => $label !== '' ? $label : (string) ($source['reference'] ?? ''),
                'url' => (($source['url'] ?? null) !== null && $source['url'] !== '') ? (string) $source['url'] : null,
            ];
        }
        ksort($notes);

        return [$numberOf, array_values($notes)];
    }

    private function marker(int $num): string
    {
        return '<sup class="fn-ref">' . $num . '</sup>';
    }

    /**
     * Search keys for a source: the full reference first, then a normalised code
     * (e.g. "R/0311/2024", "246/2019", "CI/004/2015", "3826/2025") extracted from
     * it — the body often cites just the code while the stored reference carries
     * extra wording ("Resolución 246/2019 (08/08/2019)").
     *
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private function searchKeys(array $source): array
    {
        $reference = trim((string) ($source['reference'] ?? ''));
        if ($reference === '') {
            return [];
        }

        $keys = [$reference];
        if (preg_match('~[A-ZÑ]{0,4}\s?\d+[/-]\d{2,4}(?:[/-]\d{2,4})?~u', $reference, $m)) {
            $code = trim($m[0]);
            if ($code !== '' && $code !== $reference) {
                $keys[] = $code;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function toParagraphs(string $escapedHtml): array
    {
        $blocks = preg_split('/\n{2,}/', trim($escapedHtml)) ?: [];
        $blocks = array_map('trim', $blocks);
        $blocks = array_values(array_filter($blocks, static fn (string $p): bool => $p !== ''));

        return array_map(static fn (string $p): string => nl2br($p, false), $blocks);
    }
}
