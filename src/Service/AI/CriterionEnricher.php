<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\Document\PdfRasterizer;
use Psr\Log\LoggerInterface;

/**
 * Enriches interpretive criteria with LLM help:
 *  - cleanTextFromPdf(): rasterizes every page and asks a vision LLM to return
 *    clean, faithful text. CTBG criteria PDFs are e-signed and low quality, so
 *    `pdftotext`/OCR leave signature footers and broken layout; reading the page
 *    images with the model recovers a far cleaner body for embedding.
 *  - extractSummaryAndKeypoints(): distils a one-paragraph summary and a list of
 *    doctrinal keypoints from the full text, used for the cheap screening stage
 *    and for display.
 */
final class CriterionEnricher
{
    /**
     * Pages per vision call. ONE page per call: batching multiple pages lets the
     * model bleed content across them and re-emit earlier pages, producing large
     * duplicated blocks. One page per call bounds the output and isolates each
     * page so cross-page duplication cannot happen at the model level.
     */
    private const PAGES_PER_BATCH = 1;

    /** Max pages rendered per PDF (criteria are short; this is a safety cap). */
    private const MAX_PAGES = 40;

    public function __construct(
        private readonly PdfRasterizer $pdfRasterizer,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Render every page of the PDF and have a vision LLM transcribe clean text.
     * Pages are processed in small batches and concatenated so long criteria
     * don't blow the output-token budget. Returns null if nothing usable came
     * back (caller should fall back to the pdftotext path).
     */
    public function cleanTextFromPdf(string $pdfBytes): ?string
    {
        $pages = $this->pdfRasterizer->rasterizeFromContent($pdfBytes, self::MAX_PAGES, 200);
        if ($pages === []) {
            return null;
        }

        $prompt = $this->promptStore->compile('pideinfo-criterion-clean-text');

        $sections = [];
        foreach (array_chunk($pages, self::PAGES_PER_BATCH) as $batchIndex => $batch) {
            $parts = [ContentPart::text(sprintf(
                'Transcribe el texto limpio de las siguientes %d página(s) (lote %d).',
                count($batch),
                $batchIndex + 1,
            ))];
            foreach ($batch as $png) {
                $parts[] = ContentPart::inlineData('image/png', base64_encode($png));
            }

            try {
                $result = $this->llmClient->chat(new ChatRequest(
                    systemPrompt: $prompt,
                    userParts: $parts,
                    maxOutputTokens: 16384,
                    maxRetries: 1,
                    label: 'criterion.clean-text',
                ));
                $text = $this->stripRunawayRepetition(trim($result->content));
                if ($text !== '') {
                    $sections[] = $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Criterion clean-text batch failed', [
                    'batch' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $joined = $this->dedupeLongParagraphs(trim(implode("\n\n", $sections)));

        return $joined !== '' ? $joined : null;
    }

    /**
     * Safety net against large-scale duplication (a whole block re-emitted across
     * page calls): drop later occurrences of any SUBSTANTIAL paragraph (>200
     * chars) already seen, keeping the first. Short paragraphs (headers, article
     * labels, list items) are left untouched since they legitimately recur.
     */
    private function dedupeLongParagraphs(string $text): string
    {
        $blocks = preg_split('/\n{2,}/', $text) ?: [$text];
        $seen = [];
        $kept = [];
        $dropped = 0;
        foreach ($blocks as $block) {
            $normalised = preg_replace('/\s+/u', ' ', trim($block)) ?? '';
            if (mb_strlen($normalised) > 200) {
                $hash = hash('xxh128', $normalised);
                if (isset($seen[$hash])) {
                    $dropped += mb_strlen($block);
                    continue;
                }
                $seen[$hash] = true;
            }
            $kept[] = $block;
        }

        if ($dropped > 0) {
            $this->logger->warning('Criterion clean-text: duplicate blocks removed', [
                'dropped_chars' => $dropped,
                'blocks_kept' => count($kept),
            ]);
        }

        return trim(implode("\n\n", $kept));
    }

    /**
     * Vision models occasionally fall into a repetition loop on a low-quality
     * page, emitting the same phrase hundreds of times and massively inflating
     * the output. Detect a substantial chunk (20-200 chars) repeated 4+ times
     * back-to-back and cut the text at the point the loop began — keeping the
     * good content before it. Legitimate legal text virtually never repeats a
     * 20+ char run four times consecutively, so the false-positive risk is low.
     */
    private function stripRunawayRepetition(string $text): string
    {
        if (preg_match('/(.{20,200}?)(?:\1){3,}/su', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $cut = $m[0][1];
            $kept = rtrim(mb_strcut($text, 0, $cut));
            $this->logger->warning('Criterion clean-text: runaway repetition trimmed', [
                'kept_chars' => mb_strlen($kept),
                'dropped_chars' => mb_strlen($text) - mb_strlen($kept),
            ]);

            return $kept;
        }

        return $text;
    }

    /**
     * Distil a one-paragraph summary and a list of doctrinal keypoints from the
     * criterion's full text.
     *
     * @return array{summary: string|null, keypoints: array<int, string>}
     */
    public function extractSummaryAndKeypoints(string $fullText): array
    {
        $excerpt = mb_strlen($fullText) > 40_000 ? mb_substr($fullText, 0, 40_000) : $fullText;

        $prompt = $this->promptStore->compile('pideinfo-criterion-extract');

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                userText: $excerpt,
                jsonSchema: [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['summary', 'keypoints'],
                    'properties'           => [
                        'summary'   => ['type' => 'string'],
                        'keypoints' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                schemaName: 'criterion_extract',
                maxOutputTokens: 1024,
                maxRetries: 1,
                requiredJsonKeys: ['summary', 'keypoints'],
                label: 'criterion.extract',
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Criterion summary/keypoints extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return ['summary' => null, 'keypoints' => []];
        }

        $summary = is_string($result['summary'] ?? null) ? trim($result['summary']) : null;
        $keypoints = [];
        foreach ((array) ($result['keypoints'] ?? []) as $kp) {
            $kp = trim((string) $kp);
            if ($kp !== '') {
                $keypoints[] = $kp;
            }
        }

        return [
            'summary' => $summary !== '' ? $summary : null,
            'keypoints' => $keypoints,
        ];
    }
}
