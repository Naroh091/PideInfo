<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use App\Observability\Tracer;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\CustomModelClient;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;

final class ResolutionAnalyzer
{
    private const SECTION_HEADER_REGEX = '/^[ \t]*[IVXivx]+\.\s+[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s]+?[ \t]*$/m';

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly CustomModelClient $customModelClient,
        private readonly LoggerInterface $logger,
        private readonly Tracer $tracer,
        private readonly PromptStore $promptStore,
    ) {
    }

    /**
     * Clean raw PDF text: remove headers/footers, page numbers, repeated boilerplate.
     */
    public function cleanText(string $rawText): string
    {
        $lines = explode("\n", $rawText);
        $cleaned = [];
        $seenLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines (collapse later)
            if ($trimmed === '') {
                $cleaned[] = '';
                continue;
            }

            // Skip standalone page numbers
            if (preg_match('/^\d{1,3}$/', $trimmed)) {
                continue;
            }

            // Skip page markers like "Página X de Y ..."
            if (preg_match('/^Página\s+\d+\s+de\s+\d+/i', $trimmed)) {
                continue;
            }

            // Skip common CTBG footer lines
            if (preg_match('/^(www\.consejodetransparencia\.es|reclamaciones@consejodetransparencia\.es)$/i', $trimmed)) {
                continue;
            }

            // Skip "Consejo de Transparencia y Buen Gobierno AAI" header/footer
            if (preg_match('/^Consejo de Transparencia y Buen Gobierno\s*AAI$/i', $trimmed)) {
                continue;
            }

            // Skip repeated short lines (headers/footers that appear on every page)
            // Only skip if it's short and we've seen it before
            if (mb_strlen($trimmed) < 80) {
                $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $trimmed));
                if (isset($seenLines[$normalized])) {
                    $seenLines[$normalized]++;
                    if ($seenLines[$normalized] > 2) {
                        continue;
                    }
                } else {
                    $seenLines[$normalized] = 1;
                }
            }

            // Skip HASH lines
            if (preg_match('/^HASH:\s*[a-f0-9]{32,}$/i', $trimmed)) {
                continue;
            }

            // Skip "Fecha Firma:" lines (duplicate metadata)
            if (preg_match('/^Fecha Firma:/i', $trimmed)) {
                continue;
            }

            // Skip standalone URLs (footnote references)
            if (preg_match('#^\s*https?://\S+\s*$#', $trimmed)) {
                continue;
            }

            // Skip "RA CTBG Número: XXXX-XXXX Fecha: XX/XX/XXXX" lines
            if (preg_match('/^RA\s+CTBG\s+N[úu]mero:/i', $trimmed)) {
                continue;
            }

            // Skip resolution metadata block lines
            if (preg_match('/^(Número y fecha de (la )?resolución|Número de expediente|Reclamante|Organismo|Sentido de la resolución|Palabras clave)\s*:/i', $trimmed)) {
                continue;
            }

            // Skip signer lines like "JOSE LUIS RODRIGUEZ ALVAREZ (1 de 1) Presidente"
            if (preg_match('/^\s*[A-ZÁÉÍÓÚÑ\s]{10,}\(\d+\s+de\s+\d+\)\s+\w+/u', $trimmed)) {
                continue;
            }

            $cleaned[] = $line;
        }

        $text = implode("\n", $cleaned);

        // Collapse 3+ consecutive blank lines into 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove leading/trailing whitespace
        return trim($text);
    }

    /**
     * Clean HTML-formatted resolution text: remove footnote URLs, metadata blocks, and signer lines.
     */
    public function cleanHtml(string $html): string
    {
        // Remove <p> containing only a link (footnote URLs from PDF)
        $html = preg_replace('#<p>\s*<a\s[^>]*>https?://[^<]*</a>\s*</p>#i', '', $html);

        // Remove metadata <ul> block (contains "Número y fecha de resolución:", "Organismo:", etc.)
        $html = preg_replace(
            '#<ul>\s*<li>\s*Número y fecha de (la )?resolución:.*?</ul>#is',
            '',
            $html
        );

        // Remove standalone metadata lines in <p> tags
        $html = preg_replace(
            '#<p>\s*(Número y fecha de (la )?resolución|Número de expediente|Reclamante|Organismo|Sentido de la resolución|Palabras clave)\s*:.*?</p>#is',
            '',
            $html
        );

        // Remove "RA CTBG Número:" lines
        $html = preg_replace('#<p>\s*RA\s+CTBG\s+N[úu]mero:.*?</p>#is', '', $html);

        // Remove signer lines like "JOSE LUIS RODRIGUEZ ALVAREZ (1 de 1) Presidente"
        $html = preg_replace('#<p>\s*[A-ZÁÉÍÓÚÑ\s]{10,}\(\d+\s+de\s+\d+\)\s+\w+.*?</p>#us', '', $html);

        return trim($html);
    }

    /**
     * Full analysis in two steps:
     * 1. Format text with GEMINI_SMALL_MODEL (paid, respects structured output)
     * 2. Extract analysis with GEMINI_FREE_MODEL (free, better reasoning)
     *
     * @return array{formatted_text: string, summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, subject: ?string}
     */
    public function analyze(string $cleanedText, bool $flex = false, bool $skipResolutionDate = false): array
    {
        $formatted = $this->formatText($cleanedText, flex: $flex);
        $analysis = $this->extractAnalysis($cleanedText, flex: $flex, skipResolutionDate: $skipResolutionDate);

        return array_merge($formatted, $analysis);
    }

    /**
     * Step 1: Format resolution text to semantic HTML (GEMINI_SMALL_MODEL or custom model).
     *
     * @return array{formatted_text: string}
     */
    public function formatText(string $cleanedText, bool $flex = false): array
    {
        $chunks = self::splitBySectionHeaders($cleanedText);
        $total = count($chunks);
        $prompt = $this->buildFormatTextSystemPrompt();

        return $this->tracer->span(
            name: 'resolution.formatText',
            attributes: ['chunk.count' => $total, 'text.length' => strlen($cleanedText)],
            fn: fn () => $this->doFormatText($cleanedText, $chunks, $total, $prompt, $flex),
        );
    }

    /**
     * @param array<int, array{header: ?string, body: string}> $chunks
     * @return array{formatted_text: string}
     */
    private function doFormatText(string $cleanedText, array $chunks, int $total, CompiledPrompt $prompt, bool $flex): array
    {

        if ($total === 1) {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                userText: "TEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText,
                temperature: 1.0,
                jsonSchema: $this->buildFormatTextSchema(),
                schemaName: 'resolution_analysis',
                maxOutputTokens: 65536,
                requiredJsonKeys: ['formatted_text'],
                flex: $flex,
                label: 'resolution.formatText',
            ));

            return ['formatted_text' => $result['formatted_text']];
        }

        $pieces = [];
        foreach ($chunks as $i => $chunk) {
            $oneBased = $i + 1;
            $label = sprintf('resolution.formatText.chunk[%d/%d]', $oneBased, $total);
            $chunkText = ($chunk['header'] !== null ? $chunk['header'] . "\n\n" : '') . $chunk['body'];

            try {
                $result = $this->llmClient->chatJson(new ChatRequest(
                    systemPrompt: $prompt,
                    userText: "TEXTO DE LA RESOLUCIÓN:\n\n" . $chunkText,
                    temperature: 1.0,
                    jsonSchema: $this->buildFormatTextSchema(),
                    schemaName: 'resolution_analysis',
                    maxOutputTokens: 65536,
                    requiredJsonKeys: ['formatted_text'],
                    flex: $flex,
                    label: $label,
                ));
                $pieces[] = trim((string) $result['formatted_text']);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'formatText chunk %d/%d failed, using degraded fallback',
                    $oneBased,
                    $total
                ), ['exception' => $e->getMessage(), 'label' => $label]);
                $pieces[] = trim(self::buildDegradedFormattedHtml($chunk['header'], $chunk['body']));
            }
        }

        return ['formatted_text' => implode("\n\n", array_filter($pieces, static fn ($p) => $p !== ''))];
    }

    private function buildFormatTextSystemPrompt(): CompiledPrompt
    {
        $customSuffix = "\n\nResponde ÚNICAMENTE con un JSON válido con esta estructura exacta:\n{\"formatted_text\": \"HTML formateado aquí\"}\nSÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.";

        return $this->promptStore->compile('pideinfo-resolution-format-text-system', [
            'custom_suffix' => $customSuffix,
        ]);
    }

    /**
     * Split a cleaned resolution text on Roman-numeral section headers
     * (e.g. "I. ANTECEDENTES", "II. FUNDAMENTOS JURÍDICOS").
     *
     * Returns a single chunk with header=null when fewer than 2 headers are found,
     * signalling the caller to take the legacy single-pass path. When 2+ headers
     * are found, any preamble before the first header is prepended to the first chunk.
     *
     * @return array<int, array{header: ?string, body: string}>
     */
    private static function splitBySectionHeaders(string $text): array
    {
        if (preg_match_all(self::SECTION_HEADER_REGEX, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [['header' => null, 'body' => $text]];
        }

        $hits = $matches[0];
        if (count($hits) < 2) {
            return [['header' => null, 'body' => $text]];
        }

        $chunks = [];
        $count = count($hits);
        for ($i = 0; $i < $count; $i++) {
            $rawHeader = $hits[$i][0];
            $start = $hits[$i][1];
            $end = $i + 1 < $count ? $hits[$i + 1][1] : strlen($text);

            $section = substr($text, $start, $end - $start);
            $header = rtrim($rawHeader);
            $body = ltrim(substr($section, strlen($rawHeader)));

            if ($i === 0 && $start > 0) {
                $preamble = trim(substr($text, 0, $start));
                if ($preamble !== '') {
                    $body = $preamble . "\n\n" . $body;
                }
            }

            $body = trim($body);

            if ($header === '' && $body === '') {
                continue;
            }

            $chunks[] = ['header' => $header !== '' ? $header : null, 'body' => $body];
        }

        return $chunks;
    }

    /**
     * Build degraded HTML for a chunk whose model call exhausted retries.
     * Preserves the original cleaned text content; only the formatting quality
     * of this section is degraded.
     */
    private static function buildDegradedFormattedHtml(?string $header, string $body): string
    {
        $out = '';
        if ($header !== null && $header !== '') {
            $out .= '<h2>' . htmlspecialchars($header, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</h2>';
        }

        $paragraphs = preg_split('/\n\s*\n+/', $body) ?: [];
        foreach ($paragraphs as $paragraph) {
            $trimmed = trim($paragraph);
            if ($trimmed === '') {
                continue;
            }
            $out .= '<p>' . htmlspecialchars($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFormatTextSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formatted_text' => [
                    'type' => 'string',
                    'description' => 'Full resolution text translated to Spanish (if needed) and formatted as semantic HTML. DO NOT SUMMARIZE. Must contain all original paragraphs. DO NOT REWRITE THE TEXT, JUST TRANSCRIBE IT, RESPECT THE ORIGINAL.',
                ],
            ],
            'required' => ['formatted_text'],
        ];
    }

    /**
     * Step 2: Extract summary, keypoints, dates and subject (GEMINI_MID_MODEL or custom model).
     *
     * @return array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, claim_reason: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}
     */
    public function extractAnalysis(string $cleanedText, bool $flex = false, bool $skipResolutionDate = false): array
    {
        return $this->tracer->span(
            name: 'resolution.extractAnalysis',
            attributes: ['text.length' => strlen($cleanedText)],
            fn: fn () => $this->doExtractAnalysis($cleanedText, $flex, $skipResolutionDate),
        );
    }

    /**
     * @return array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, claim_reason: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}
     */
    private function doExtractAnalysis(string $cleanedText, bool $flex, bool $skipResolutionDate): array
    {
        $customSuffix = $skipResolutionDate
            ? "\n\nResponde ÚNICAMENTE con un JSON válido con esta estructura exacta:\n{\"summary\": \"resumen en texto plano\", \"keypoints\": [\"punto 1\", \"punto 2\", ...], \"claim_date\": \"YYYY-MM-DD o null\", \"claim_reason\": \"frase corta o null\", \"subject\": \"asunto en castellano o null\", \"info_request_date\": \"YYYY-MM-DD o null\", \"complained_administration\": \"nombre o null\", \"outcome\": \"código del enum o null\", \"limits\": [\"código\", ...], \"inadmission_causes\": [\"código\", ...]}\nSÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO."
            : "\n\nResponde ÚNICAMENTE con un JSON válido con esta estructura exacta:\n{\"summary\": \"resumen en texto plano\", \"keypoints\": [\"punto 1\", \"punto 2\", ...], \"resolution_date\": \"YYYY-MM-DD o null\", \"claim_date\": \"YYYY-MM-DD o null\", \"claim_reason\": \"frase corta o null\", \"subject\": \"asunto en castellano o null\", \"info_request_date\": \"YYYY-MM-DD o null\", \"complained_administration\": \"nombre o null\", \"outcome\": \"código del enum o null\", \"limits\": [\"código\", ...], \"inadmission_causes\": [\"código\", ...]}\nSÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.";
        $prompt = $this->buildExtractAnalysisPrompt(skipResolutionDate: $skipResolutionDate, customSuffix: $customSuffix);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            userText: "TEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText,
            temperature: 1.0,
            jsonSchema: $this->buildExtractAnalysisSchema(skipResolutionDate: $skipResolutionDate),
            schemaName: 'resolution_analysis',
            maxOutputTokens: 65536,
            requiredJsonKeys: ['summary', 'keypoints'],
            flex: $flex,
            label: 'resolution.extractAnalysis',
        ));

        return $this->normalizeExtractAnalysisResult($result);
    }

    /**
     * Batch format: send multiple resolution texts in one call, get array of formatted results.
     * Only works with custom model.
     *
     * @param array<int, string> $cleanedTexts Indexed array of cleaned texts
     * @return array<int, array{formatted_text: string}> Results indexed by same keys
     */
    public function batchFormatText(array $cleanedTexts): array
    {
        $prompt = $this->promptStore->compile('pideinfo-resolution-format-text-batch');

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $schema = $this->wrapBatchSchema($this->buildFormatTextSchema());

        return $this->callCustomModelBatchApi($prompt->text, $userContent, $cleanedTexts, ['formatted_text'], $schema);
    }

    /**
     * Batch analyze: send multiple resolution texts in one call, get array of analysis results.
     * Only works with custom model.
     *
     * @param array<int, string> $cleanedTexts Indexed array of cleaned texts
     * @return array<int, array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, claim_reason: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}> Results indexed by same keys
     */
    public function batchExtractAnalysis(array $cleanedTexts): array
    {
        $prompt = $this->promptStore->compile('pideinfo-resolution-extract-analysis-batch', [
            'base_prompt' => $this->buildExtractAnalysisPrompt(),
        ]);

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $schema = $this->wrapBatchSchema($this->buildExtractAnalysisSchema());
        $results = $this->callCustomModelBatchApi($prompt->text, $userContent, $cleanedTexts, ['summary', 'keypoints'], $schema);

        foreach ($results as $i => $result) {
            /** @var array<string, mixed> $result */
            $results[$i] = $this->normalizeExtractAnalysisResult($result);
        }

        /** @var array<int, array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, claim_reason: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}> $results */
        return $results;
    }

    /**
     * Extract only the "non-complete" backfill fields: info_request_date, complained_administration,
     * claim_reason, outcome, limits and inadmission_causes. Leaves summary, keypoints, subject and
     * signature/claim dates untouched so it can be used to top up resolutions that were analyzed
     * before these fields existed.
     *
     * @return array{info_request_date: ?string, complained_administration: ?string, claim_reason: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}
     */
    public function extractNonCompleteAnalysis(string $cleanedText, bool $flex = false): array
    {
        $customSuffix = "\n\nResponde ÚNICAMENTE con un JSON válido con esta estructura exacta:\n{\"info_request_date\": \"YYYY-MM-DD o null\", \"complained_administration\": \"nombre o null\", \"claim_reason\": \"frase corta o null\", \"outcome\": \"código del enum o null\", \"limits\": [\"código\", ...], \"inadmission_causes\": [\"código\", ...]}\nSÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.";
        $prompt = $this->buildNonCompleteAnalysisPrompt($customSuffix);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            userText: "TEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText,
            temperature: 1.0,
            jsonSchema: $this->buildNonCompleteAnalysisSchema(),
            schemaName: 'resolution_analysis',
            maxOutputTokens: 65536,
            requiredJsonKeys: ['limits', 'inadmission_causes'],
            flex: $flex,
            label: 'resolution.extractNonCompleteAnalysis',
        ));

        return $this->normalizeNonCompleteAnalysisResult($result);
    }

    /**
     * Batch variant of extractNonCompleteAnalysis. Only works with custom model.
     *
     * @param array<int, string> $cleanedTexts Indexed array of cleaned texts
     * @return array<int, array{info_request_date: ?string, complained_administration: ?string, claim_reason: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}>
     */
    public function batchExtractNonCompleteAnalysis(array $cleanedTexts): array
    {
        $basePrompt = $this->buildNonCompleteAnalysisPrompt();
        $batchHeader = "Se te proporcionan varios textos de resoluciones, cada uno identificado por un número. Analiza CADA resolución y aplica las siguientes instrucciones a cada una de forma independiente.\n\n";
        $batchFooter = <<<'FOOTER'


Responde ÚNICAMENTE con un JSON válido con la estructura {"results": [{"index": N, "info_request_date": "YYYY-MM-DD o null", "complained_administration": "... o null", "claim_reason": "... o null", "outcome": "código o null", "limits": ["código", ...], "inadmission_causes": ["código", ...]}, ...]}.
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
FOOTER;

        $prompt = $batchHeader . $basePrompt->text . $batchFooter;

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $schema = $this->wrapBatchSchema($this->buildNonCompleteAnalysisSchema());
        $results = $this->callCustomModelBatchApi($prompt, $userContent, $cleanedTexts, ['limits', 'inadmission_causes'], $schema);

        foreach ($results as $i => $result) {
            /** @var array<string, mixed> $result */
            $results[$i] = $this->normalizeNonCompleteAnalysisResult($result);
        }

        /** @var array<int, array{info_request_date: ?string, complained_administration: ?string, claim_reason: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}> $results */
        return $results;
    }

    /**
     * @param array<int, string> $cleanedTexts
     */
    private function buildBatchUserContent(array $cleanedTexts): string
    {
        $parts = [];
        foreach ($cleanedTexts as $index => $text) {
            $parts[] = "=== RESOLUCIÓN $index ===\n\n$text";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Wrap a single-item object schema into a `{results: [item, ...]}` root schema for
     * batch calls, and inject the `index` field required to map results back to inputs.
     *
     * @param array<string, mixed> $itemSchema
     * @return array<string, mixed>
     */
    private function wrapBatchSchema(array $itemSchema): array
    {
        $properties = $itemSchema['properties'] ?? [];
        $itemSchema['properties'] = array_merge(
            ['index' => ['type' => 'integer', 'description' => 'Original input index for mapping']],
            $properties,
        );
        $itemSchema['required'] = array_values(array_unique(array_merge(
            ['index'],
            $itemSchema['required'] ?? [],
        )));

        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => $itemSchema,
                    'description' => 'One entry per input resolution, keyed by its original index',
                ],
            ],
            'required' => ['results'],
        ];
    }

    /**
     * Custom-model-only batch entrypoint. Sends N inputs in a single multi-resolution prompt,
     * decodes the response, validates required keys per item and maps results back by index.
     *
     * @param array<int, string> $cleanedTexts Original indexed texts (for key mapping)
     * @param string[] $requiredKeys Keys each result item must have
     * @param array<string, mixed> $responseSchema Root JSON schema (should be a `{results: [...]}` wrapper)
     * @return array<int, array<string, mixed>> Results indexed by original keys
     */
    private function callCustomModelBatchApi(string $prompt, string $userContent, array $cleanedTexts, array $requiredKeys, array $responseSchema, int $maxRetries = 2): array
    {
        $lastError = null;
        $expectedIndices = array_keys($cleanedTexts);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying custom model batch API call (attempt %d/%d)', $attempt + 1, $maxRetries + 1));
                usleep(500_000 * $attempt);
            }

            try {
                $content = $this->customModelClient->chatRaw(
                    messages: [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                    jsonSchema: $responseSchema,
                    schemaName: 'resolution_analysis_batch',
                    maxRetries: 0,
                )->content;
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $content = trim($content);

            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
                $content = trim($content);
            }

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Invalid JSON from custom model batch, will retry', [
                    'attempt' => $attempt + 1,
                    'response_preview' => mb_substr($content, 0, 300),
                    'json_error' => json_last_error_msg(),
                ]);
                $lastError = new \RuntimeException('Invalid JSON from custom model batch: ' . json_last_error_msg());
                continue;
            }

            $items = $decoded;
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $items = $decoded['results'];
            }

            if (!is_array($items) || empty($items)) {
                $lastError = new \RuntimeException('Custom model batch response is not a valid array.');
                continue;
            }

            $mapped = [];
            foreach ($items as $item) {
                if (!isset($item['index'])) {
                    continue;
                }
                $idx = (int) $item['index'];
                if (!in_array($idx, $expectedIndices, true)) {
                    continue;
                }
                $valid = true;
                foreach ($requiredKeys as $key) {
                    if (!array_key_exists($key, $item)) {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    unset($item['index']);
                    $mapped[$idx] = $item;
                }
            }

            if (empty($mapped)) {
                $lastError = new \RuntimeException('Custom model batch returned no valid results.');
                continue;
            }

            $missing = array_diff($expectedIndices, array_keys($mapped));
            if (!empty($missing)) {
                $this->logger->warning('Custom model batch response missing some indices', [
                    'missing' => $missing,
                    'returned' => count($mapped),
                    'expected' => count($expectedIndices),
                ]);
            }

            return $mapped;
        }

        throw $lastError ?? new \RuntimeException(sprintf('Custom model batch API call failed after %d attempts.', $maxRetries + 1));
    }

    public function buildExtractAnalysisPrompt(bool $skipResolutionDate = false, string $customSuffix = ''): CompiledPrompt
    {
        return $this->promptStore->compile('pideinfo-resolution-extract-analysis', [
            'outcomes_block' => self::renderEnumBlock(Resolution::getOutcomeLabels()),
            'limits_block' => self::renderEnumBlock(Resolution::getLimitLabels()),
            'causes_block' => self::renderEnumBlock(Resolution::getInadmissionCauseLabels()),
            'resolution_date_block' => $skipResolutionDate ? '' : "[resolution_date]\nFecha de firma de la resolución del organismo de transparencia. Suele aparecer al final del documento, junto a la firma, o en el encabezado. Formato ISO 8601 (YYYY-MM-DD). Null solo si de verdad no aparece.\n\n",
            'custom_suffix' => $customSuffix,
        ]);
    }
    /**
     * @param array<string, string> $labels
     */
    private static function renderEnumBlock(array $labels): string
    {
        $lines = [];
        foreach ($labels as $code => $label) {
            $lines[] = sprintf('- %s: %s', $code, $label);
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExtractAnalysisSchema(bool $skipResolutionDate = false): array
    {
        $outcomeCodes = array_keys(Resolution::getOutcomeLabels());
        $limitCodes = array_keys(Resolution::getLimitLabels());
        $causeCodes = array_keys(Resolution::getInadmissionCauseLabels());

        $properties = [
            'summary' => [
                'type' => 'string',
                'description' => 'Concise plain-text summary in Spanish, max 400 characters.',
            ],
            'keypoints' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => '3-7 key legal reasoning points in Spanish.',
            ],
        ];

        if (!$skipResolutionDate) {
            $properties['resolution_date'] = [
                'type' => 'string',
                'nullable' => true,
                'description' => 'Resolution signature date in ISO 8601 (YYYY-MM-DD). Null if not found.',
            ];
        }

        $properties['claim_date'] = [
            'type' => 'string',
            'nullable' => true,
            'description' => 'Claim filing date (before the transparency council) in ISO 8601. Null if not found.',
        ];
        $properties['claim_reason'] = [
            'type' => 'string',
            'nullable' => true,
            'description' => 'Short sentence (max 120 chars) describing what the claimant is protesting against (silencio administrativo, a specific art. 14 limit, an art. 18 inadmission cause, partial access, etc.).',
        ];
        $properties['info_request_date'] = [
            'type' => 'string',
            'nullable' => true,
            'description' => 'Original date when the citizen requested information from the administration (NOT the claim date). ISO 8601, null if not found.',
        ];
        $properties['complained_administration'] = [
            'type' => 'string',
            'nullable' => true,
            'description' => 'Name of the administration the citizen complained about. Never the transparency council. Null if unclear.',
        ];
        $properties['subject'] = [
            'type' => 'string',
            'nullable' => true,
            'description' => 'Brief description of the requested information in Spanish, max 300 chars.',
        ];
        $properties['outcome'] = [
            'type' => 'string',
            'nullable' => true,
            'enum' => array_merge($outcomeCodes, [null]),
            'description' => 'Outcome code. Must be one of the canonical enum values, or null if undetermined.',
        ];
        $properties['limits'] = [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => $limitCodes],
            'description' => 'Codes of art. 14 limits invoked by the administration. Empty array if none.',
        ];
        $properties['inadmission_causes'] = [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => $causeCodes],
            'description' => 'Codes of art. 18 inadmission causes invoked by the administration. Empty array if none.',
        ];

        $required = ['summary', 'keypoints', 'claim_date', 'claim_reason', 'info_request_date', 'complained_administration', 'subject', 'outcome', 'limits', 'inadmission_causes'];
        if (!$skipResolutionDate) {
            array_splice($required, 2, 0, ['resolution_date']);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}
     */
    private function normalizeExtractAnalysisResult(array $result): array
    {
        $result['resolution_date'] = $result['resolution_date'] ?? null;
        $result['claim_date'] = $result['claim_date'] ?? null;
        $result['claim_reason'] = $result['claim_reason'] ?? null;
        $result['subject'] = $result['subject'] ?? null;
        $result['info_request_date'] = $result['info_request_date'] ?? null;
        $result['complained_administration'] = $result['complained_administration'] ?? null;
        $result['outcome'] = $result['outcome'] ?? null;
        $result['limits'] = is_array($result['limits'] ?? null) ? array_values(array_filter($result['limits'], 'is_string')) : [];
        $result['inadmission_causes'] = is_array($result['inadmission_causes'] ?? null) ? array_values(array_filter($result['inadmission_causes'], 'is_string')) : [];

        /** @var array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, claim_reason: ?string, subject: ?string, info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>} $result */
        return $result;
    }

    public function buildNonCompleteAnalysisPrompt(string $customSuffix = ''): CompiledPrompt
    {
        return $this->promptStore->compile('pideinfo-resolution-extract-noncomplete', [
            'outcomes_block' => self::renderEnumBlock(Resolution::getOutcomeLabels()),
            'limits_block' => self::renderEnumBlock(Resolution::getLimitLabels()),
            'causes_block' => self::renderEnumBlock(Resolution::getInadmissionCauseLabels()),
            'custom_suffix' => $customSuffix,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNonCompleteAnalysisSchema(): array
    {
        $outcomeCodes = array_keys(Resolution::getOutcomeLabels());
        $limitCodes = array_keys(Resolution::getLimitLabels());
        $causeCodes = array_keys(Resolution::getInadmissionCauseLabels());

        return [
            'type' => 'object',
            'properties' => [
                'info_request_date' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Original date when the citizen requested information from the administration (NOT the claim date). ISO 8601, null if not found.',
                ],
                'complained_administration' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Name of the administration the citizen complained about. Never the transparency council. Null if unclear.',
                ],
                'claim_reason' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Short sentence (max 120 chars) describing what the claimant is protesting against.',
                ],
                'outcome' => [
                    'type' => 'string',
                    'nullable' => true,
                    'enum' => array_merge($outcomeCodes, [null]),
                    'description' => 'Outcome code. Must be one of the canonical enum values, or null if undetermined.',
                ],
                'limits' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $limitCodes],
                    'description' => 'Codes of art. 14 limits invoked by the administration. Empty array if none.',
                ],
                'inadmission_causes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $causeCodes],
                    'description' => 'Codes of art. 18 inadmission causes invoked by the administration. Empty array if none.',
                ],
            ],
            'required' => [
                'info_request_date',
                'complained_administration',
                'claim_reason',
                'outcome',
                'limits',
                'inadmission_causes',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{info_request_date: ?string, complained_administration: ?string, outcome: ?string, limits: array<string>, inadmission_causes: array<string>}
     */
    private function normalizeNonCompleteAnalysisResult(array $result): array
    {
        $normalized = [
            'info_request_date' => $result['info_request_date'] ?? null,
            'complained_administration' => $result['complained_administration'] ?? null,
            'claim_reason' => $result['claim_reason'] ?? null,
            'outcome' => $result['outcome'] ?? null,
            'limits' => is_array($result['limits'] ?? null) ? array_values(array_filter($result['limits'], 'is_string')) : [],
            'inadmission_causes' => is_array($result['inadmission_causes'] ?? null) ? array_values(array_filter($result['inadmission_causes'], 'is_string')) : [],
        ];

        return $normalized;
    }

    /**
     * Apply an extractAnalysis result to a Resolution entity.
     *
     * Centralizes merge rules so inline, async, custom-batch and Gemini-batch paths stay in sync.
     *
     * @param array<string, mixed> $result Raw or normalized result from extractAnalysis / batchExtractAnalysis.
     */
    public function applyAnalysisResult(Resolution $resolution, array $result): void
    {
        if (isset($result['summary']) && is_string($result['summary'])) {
            $resolution->setSummary($result['summary']);
        }

        if (isset($result['keypoints']) && is_array($result['keypoints'])) {
            $resolution->setKeypoints(array_values(array_filter($result['keypoints'], 'is_string')));
        }

        if (!empty($result['subject']) && is_string($result['subject'])) {
            $resolution->setSubject(mb_substr($result['subject'], 0, 500));
        }

        $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
        if (!empty($result['resolution_date']) && is_string($result['resolution_date']) && $existingDateSource === null) {
            try {
                $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'LLM';
                $resolution->setSourceMetadata($meta);
            } catch (\Exception) {
            }
        }

        if (!empty($result['claim_date']) && is_string($result['claim_date'])) {
            try {
                $resolution->setClaimDate(new \DateTimeImmutable($result['claim_date']));
            } catch (\Exception) {
            }
        }

        if (!empty($result['claim_reason']) && is_string($result['claim_reason'])) {
            $resolution->setClaimReason(mb_substr($result['claim_reason'], 0, 500));
        }

        if (!empty($result['info_request_date']) && is_string($result['info_request_date'])) {
            try {
                $resolution->setInfoRequestDate(new \DateTimeImmutable($result['info_request_date']));
            } catch (\Exception) {
            }
        }

        if (
            !empty($result['complained_administration'])
            && is_string($result['complained_administration'])
            && empty($resolution->getPublicBodyName())
        ) {
            $resolution->setPublicBodyName(mb_substr($result['complained_administration'], 0, 1000));
        }

        if (isset($result['limits']) && is_array($result['limits'])) {
            $resolution->setLimits(array_values(array_filter($result['limits'], 'is_string')));
        }

        if (isset($result['inadmission_causes']) && is_array($result['inadmission_causes'])) {
            $resolution->setInadmissionCauses(array_values(array_filter($result['inadmission_causes'], 'is_string')));
        }

        if (!empty($result['outcome']) && is_string($result['outcome'])) {
            $this->applyOutcome($resolution, $result['outcome']);
        }

    }

    private function applyOutcome(Resolution $resolution, string $llmOutcome): void
    {
        $validOutcomes = array_keys(Resolution::getOutcomeLabels());
        $meta = $resolution->getSourceMetadata() ?? [];

        if (in_array($llmOutcome, $validOutcomes, true)) {
            $current = $resolution->getOutcome();
            if ($current !== $llmOutcome) {
                $meta[Resolution::META_OUTCOME_OVERRIDEN] = [
                    'previous' => $current,
                    'new' => $llmOutcome,
                ];
                $resolution->setOutcome($llmOutcome);
                $resolution->setSourceMetadata($meta);
            }
            return;
        }

        $meta[Resolution::META_OUTCOME_RAW] = mb_substr($llmOutcome, 0, 200);
        $resolution->setSourceMetadata($meta);
    }
}
