<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client as OpenAIClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionAnalyzer
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private ?OpenAIClient $customClient = null;

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $smallModel,
        #[Autowire(env: 'GEMINI_MID_MODEL')]
        private readonly string $midModel,
        #[Autowire(env: 'bool:USE_CUSTOM_MODEL')]
        private readonly bool $useCustomModel,
        #[Autowire(env: 'CUSTOM_MODEL')]
        private readonly string $customModel,
        #[Autowire(env: 'CUSTOM_MODEL_ENDPOINT')]
        private readonly string $customModelEndpoint,
        #[Autowire(env: 'CUSTOM_MODEL_API_KEY')]
        private readonly string $customModelApiKey,
        #[Autowire(env: 'int:CUSTOM_MODEL_MAX_TOKENS')]
        private readonly int $customModelMaxTokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function getCustomClient(): OpenAIClient
    {
        if ($this->customClient === null) {
            $factory = OpenAI::factory()
                ->withHttpClient(new GuzzleClient(['timeout' => 600]))
                ->withBaseUri($this->customModelEndpoint);

            if ($this->customModelApiKey !== '') {
                $factory = $factory->withApiKey($this->customModelApiKey);
            } else {
                $factory = $factory->withApiKey('no-key');
            }

            $this->customClient = $factory->make();
        }

        return $this->customClient;
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
    public function analyze(string $cleanedText, bool $flex = false): array
    {
        $formatted = $this->formatText($cleanedText, flex: $flex);
        $analysis = $this->extractAnalysis($cleanedText, flex: $flex);

        return array_merge($formatted, $analysis);
    }

    /**
     * Step 1: Format resolution text to semantic HTML (GEMINI_SMALL_MODEL or custom model).
     *
     * @return array{formatted_text: string}
     */
    public function formatText(string $cleanedText, bool $flex = false): array
    {
        $prompt = <<<'PROMPT'
Actúa como un experto en derecho administrativo español. Formatea el texto de la resolución adjunta cumpliendo ESTRICTAMENTE las siguientes reglas.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR TRADUCIDA AL CASTELLANO. Utiliza terminología jurídica precisa en castellano.

[formatted_text]
- Transcribe TODO el texto principal (traducido si aplica) usando HTML semántico. ES VITAL QUE NO RESUMAS ESTE CAMPO; debe contener todo el contenido original. NO REDACTES LAS COSAS DE FORMA DISTINTA A LA ORIGINAL.
- Limpia artefactos: une párrafos cortados y elimina espacios extra.
- Etiquetas permitidas: <h2>, <h3>, <p>, <strong>, <em>, <ol>, <ul>, <li>, <blockquote>, <a>, <cite>, <br>, <hr>.
- Jerarquía: <h2> para secciones principales (ANTECEDENTES, FUNDAMENTOS JURÍDICOS, RESOLUCIÓN), <h3> para subsecciones.
- Estilos: <strong> (términos legales, organismos), <em> (citas de solicitudes), <blockquote> (citas extensas/leyes), <cite> (leyes 1ª vez).
- ELIMINA: Metadatos iniciales/finales ("Número de expediente:", "Reclamante:", etc.), firmas, cabeceras del archivo y URLs sueltas no integradas en el texto.
- PROHIBIDO: Usar <html>, <head>, <body> o estilos/clases CSS.
PROMPT;

        $schema = $this->buildFormatTextSchema();

        if ($this->useCustomModel) {
            $jsonSuffix = <<<'SUFFIX'

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{"formatted_text": "HTML formateado aquí"}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;

            return $this->callCustomModelApi($prompt . $jsonSuffix, $cleanedText, ['formatted_text'], $schema);
        }

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        return $this->callGeminiApi($this->smallModel, $parts, $schema, flex: $flex);
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
    public function extractAnalysis(string $cleanedText, bool $flex = false): array
    {
        $prompt = self::buildExtractAnalysisPrompt();

        if ($this->useCustomModel) {
            $jsonSuffix = <<<'SUFFIX'

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{"summary": "resumen en texto plano", "keypoints": ["punto 1", "punto 2", ...], "resolution_date": "YYYY-MM-DD o null", "claim_date": "YYYY-MM-DD o null", "claim_reason": "frase corta o null", "subject": "asunto en castellano o null", "info_request_date": "YYYY-MM-DD o null", "complained_administration": "nombre o null", "outcome": "código del enum o null", "limits": ["código", ...], "inadmission_causes": ["código", ...]}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;

            $result = $this->callCustomModelApi($prompt . $jsonSuffix, $cleanedText, ['summary', 'keypoints'], $this->buildExtractAnalysisSchema());

            return $this->normalizeExtractAnalysisResult($result);
        }

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        $schema = $this->buildExtractAnalysisSchema();

        $result = $this->callGeminiApi($this->midModel, $parts, $schema, flex: $flex);

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
        $prompt = <<<'PROMPT'
Actúa como un experto en derecho administrativo español. Se te proporcionan varios textos de resoluciones, cada uno identificado por un número.

Para CADA resolución, formatea el texto cumpliendo ESTRICTAMENTE estas reglas:

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR TRADUCIDA AL CASTELLANO. Utiliza terminología jurídica precisa en castellano.

[formatted_text]
- Transcribe TODO el texto principal (traducido si aplica) usando HTML semántico. ES VITAL QUE NO RESUMAS; debe contener todo el contenido original. NO REDACTES LAS COSAS DE FORMA DISTINTA A LA ORIGINAL.
- Limpia artefactos: une párrafos cortados y elimina espacios extra.
- Etiquetas permitidas: <h2>, <h3>, <p>, <strong>, <em>, <ol>, <ul>, <li>, <blockquote>, <a>, <cite>, <br>, <hr>.
- Jerarquía: <h2> para secciones principales (ANTECEDENTES, FUNDAMENTOS JURÍDICOS, RESOLUCIÓN), <h3> para subsecciones.
- Estilos: <strong> (términos legales, organismos), <em> (citas de solicitudes), <blockquote> (citas extensas/leyes), <cite> (leyes 1ª vez).
- ELIMINA: Metadatos iniciales/finales ("Número de expediente:", "Reclamante:", etc.), firmas, cabeceras del archivo y URLs sueltas no integradas en el texto.
- PROHIBIDO: Usar <html>, <head>, <body> o estilos/clases CSS.

Responde ÚNICAMENTE con un JSON válido con la estructura {"results": [{"index": N, "formatted_text": "HTML"}, ...]}.
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
PROMPT;

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $schema = $this->wrapBatchSchema($this->buildFormatTextSchema());

        return $this->callCustomModelBatchApi($prompt, $userContent, $cleanedTexts, ['formatted_text'], $schema);
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
        $basePrompt = self::buildExtractAnalysisPrompt();
        $batchHeader = "Se te proporcionan varios textos de resoluciones, cada uno identificado por un número. Analiza CADA resolución y aplica las siguientes instrucciones a cada una de forma independiente.\n\n";
        $batchFooter = <<<'FOOTER'


Responde ÚNICAMENTE con un JSON válido con la estructura {"results": [{"index": N, "summary": "...", "keypoints": [...], "resolution_date": "YYYY-MM-DD o null", "claim_date": "YYYY-MM-DD o null", "claim_reason": "... o null", "info_request_date": "YYYY-MM-DD o null", "complained_administration": "... o null", "subject": "... o null", "outcome": "código o null", "limits": ["código", ...], "inadmission_causes": ["código", ...]}, ...]}.
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
FOOTER;

        $prompt = $batchHeader . $basePrompt . $batchFooter;

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $schema = $this->wrapBatchSchema($this->buildExtractAnalysisSchema());
        $results = $this->callCustomModelBatchApi($prompt, $userContent, $cleanedTexts, ['summary', 'keypoints'], $schema);

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
        $prompt = self::buildNonCompleteAnalysisPrompt();

        if ($this->useCustomModel) {
            $jsonSuffix = <<<'SUFFIX'

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{"info_request_date": "YYYY-MM-DD o null", "complained_administration": "nombre o null", "claim_reason": "frase corta o null", "outcome": "código del enum o null", "limits": ["código", ...], "inadmission_causes": ["código", ...]}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;

            $result = $this->callCustomModelApi($prompt . $jsonSuffix, $cleanedText, ['limits', 'inadmission_causes'], $this->buildNonCompleteAnalysisSchema());

            return $this->normalizeNonCompleteAnalysisResult($result);
        }

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        $schema = $this->buildNonCompleteAnalysisSchema();

        $result = $this->callGeminiApi($this->midModel, $parts, $schema, flex: $flex);

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
        $basePrompt = self::buildNonCompleteAnalysisPrompt();
        $batchHeader = "Se te proporcionan varios textos de resoluciones, cada uno identificado por un número. Analiza CADA resolución y aplica las siguientes instrucciones a cada una de forma independiente.\n\n";
        $batchFooter = <<<'FOOTER'


Responde ÚNICAMENTE con un JSON válido con la estructura {"results": [{"index": N, "info_request_date": "YYYY-MM-DD o null", "complained_administration": "... o null", "claim_reason": "... o null", "outcome": "código o null", "limits": ["código", ...], "inadmission_causes": ["código", ...]}, ...]}.
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
FOOTER;

        $prompt = $batchHeader . $basePrompt . $batchFooter;

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
     * Build the response_format payload for a structured-output chat completion.
     *
     * We use the OpenAI-compatible `json_schema` variant — this is honored by vLLM, lmdeploy
     * and other modern self-hosted OpenAI-compatible servers via guided decoding. We
     * deliberately do NOT pass `strict: true` because our schemas use `nullable: true` which
     * is incompatible with OpenAI's strict mode (it requires `type: ["X", "null"]`). `strict`
     * false is enough: the server constrains decoding to the schema without the strict-mode
     * extra validation rules.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function buildJsonSchemaResponseFormat(array $schema, string $name = 'resolution_analysis'): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $name,
                'schema' => $schema,
            ],
        ];
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
                $response = $this->getCustomClient()->chat()->create([
                    'model' => $this->customModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => $this->customModelMaxTokens,
                    'response_format' => $this->buildJsonSchemaResponseFormat($responseSchema, 'resolution_analysis_batch'),
                ]);
            } catch (OpenAI\Exceptions\RateLimitException $e) {
                $lastError = new \RuntimeException('Custom model rate limit exceeded: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\TransporterException $e) {
                $lastError = new \RuntimeException('Custom model transport error: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\ErrorException $e) {
                $lastError = new \RuntimeException('Custom model API error: ' . $e->getMessage(), 0, $e);
                if ($e->getCode() >= 500 || $e->getCode() === 0) {
                    continue;
                }
                throw $lastError;
            }

            $content = $response->choices[0]->message->content ?? null;
            if (!$content || strlen(trim($content)) < 10) {
                $lastError = new \RuntimeException('Empty response from custom model batch call.');
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

            // Normalize: accept both a plain array and {"results": [...]}
            $items = $decoded;
            if (isset($decoded['results']) && is_array($decoded['results'])) {
                $items = $decoded['results'];
            }

            if (!is_array($items) || empty($items)) {
                $lastError = new \RuntimeException('Custom model batch response is not a valid array.');
                continue;
            }

            // Map results by index
            $mapped = [];
            foreach ($items as $item) {
                if (!isset($item['index'])) {
                    continue;
                }
                $idx = (int) $item['index'];
                if (!in_array($idx, $expectedIndices, true)) {
                    continue;
                }
                // Validate required keys
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

            // Log if some resolutions are missing from the response
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

    /**
     * @param string[] $requiredKeys Keys that must be present in the returned JSON
     * @param array<string, mixed> $responseSchema Root JSON schema enforced via structured outputs
     * @return array<string, mixed>
     */
    private function callCustomModelApi(string $prompt, string $text, array $requiredKeys, array $responseSchema, int $maxRetries = 2): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying custom model API call (attempt %d/%d)', $attempt + 1, $maxRetries + 1));
                usleep(500_000 * $attempt);
            }

            try {
                $response = $this->getCustomClient()->chat()->create([
                    'model' => $this->customModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => "TEXTO DE LA RESOLUCIÓN:\n\n" . $text],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => $this->customModelMaxTokens,
                    'response_format' => $this->buildJsonSchemaResponseFormat($responseSchema),
                ]);
            } catch (OpenAI\Exceptions\RateLimitException $e) {
                $lastError = new \RuntimeException('Custom model rate limit exceeded: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\TransporterException $e) {
                $lastError = new \RuntimeException('Custom model transport error: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\ErrorException $e) {
                $lastError = new \RuntimeException('Custom model API error: ' . $e->getMessage(), 0, $e);
                // Only retry on server errors (5xx equivalent)
                if ($e->getCode() >= 500 || $e->getCode() === 0) {
                    continue;
                }
                throw $lastError;
            }

            $content = $response->choices[0]->message->content ?? null;
            if (!$content || strlen(trim($content)) < 10) {
                $lastError = new \RuntimeException('Empty response from custom model.');
                continue;
            }

            $content = trim($content);

            // Strip markdown code fences if the model wraps JSON in them
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
                $content = trim($content);
            }

            $result = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Invalid JSON from custom model, will retry', [
                    'attempt' => $attempt + 1,
                    'response_length' => strlen($content),
                    'response_preview' => mb_substr($content, 0, 200),
                    'json_error' => json_last_error_msg(),
                ]);
                $lastError = new \RuntimeException('Invalid JSON from custom model: ' . json_last_error_msg());
                continue;
            }

            // Validate required keys
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $result)) {
                    $this->logger->warning('Custom model response missing required key', [
                        'missing_key' => $key,
                        'attempt' => $attempt + 1,
                    ]);
                    $lastError = new \RuntimeException(sprintf('Custom model response missing key: %s', $key));
                    continue 2;
                }
            }

            return $result;
        }

        throw $lastError ?? new \RuntimeException(sprintf('Custom model API call failed after %d attempts.', $maxRetries + 1));
    }

    private function callGeminiApi(string $model, array $parts, array $schema, bool $flex = false, int $maxRetries = 2): array
    {
        $url = sprintf(self::GEMINI_ENDPOINT, $model) . '?key=' . $this->geminiApiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topP' => 0.95,
                'maxOutputTokens' => 65536,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        if ($flex) {
            $payload['service_tier'] = 'flex';
        }

        $jsonPayload = json_encode($payload);
        $lastError = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying Gemini API call (attempt %d/%d, model %s)', $attempt + 1, $maxRetries + 1, $model));
                usleep(500_000 * $attempt); // 0.5s, 1s backoff
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);

            if ($response === false) {
                $lastError = new \RuntimeException('cURL error during Gemini API call: ' . curl_error($ch));
                curl_close($ch);
                continue;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $lastError = new \RuntimeException(sprintf('Gemini API error (HTTP %d, model %s): %s', $httpCode, $model, $response));
                // Only retry on 429 (rate limit) or 5xx (server error)
                if ($httpCode !== 429 && $httpCode < 500) {
                    throw $lastError;
                }
                continue;
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = new \RuntimeException('Failed to decode Gemini API response: ' . json_last_error_msg());
                continue;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$text || strlen(trim($text)) < 10) {
                $lastError = new \RuntimeException(sprintf('Empty response from Gemini API (model %s).', $model));
                continue;
            }

            // Strip whitespace-only garbage responses before parsing
            $text = trim($text);

            $result = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Invalid Gemini JSON response, will retry', [
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'response_length' => strlen($text),
                    'response_preview' => mb_substr($text, 0, 200),
                    'json_error' => json_last_error_msg(),
                ]);
                $lastError = new \RuntimeException(sprintf('Invalid JSON from Gemini (model %s).', $model));
                continue;
            }

            return $result;
        }

        throw $lastError ?? new \RuntimeException(sprintf('Gemini API call failed after %d attempts (model %s).', $maxRetries + 1, $model));
    }

    public static function buildExtractAnalysisPrompt(): string
    {
        $outcomesBlock = self::renderEnumBlock(Resolution::getOutcomeLabels());
        $limitsBlock = self::renderEnumBlock(Resolution::getLimitLabels());
        $causesBlock = self::renderEnumBlock(Resolution::getInadmissionCauseLabels());

        return <<<PROMPT
Actúa como un experto en derecho administrativo español y transparencia. Analiza la resolución adjunta y extrae la información requerida.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

[summary]
Escribe un resumen directo en texto plano (máximo 400 caracteres).
Explica: 1) Qué se solicitó y a quién. 2) Si se alegó algo 3) Decisión del organismo de transparencia (presta atención a su nombre) y por qué.

[keypoints]
Extrae de 3 a 7 frases completas con los argumentos jurídicos clave (precedentes, argumentación jurídica de los motivos de estimación/desestimación).
Evita que las frases se parezcan demasiado, si se parecen condénsalas en una.
Evita formalidades comunes (por ejemplo, "La ley reconoce el derecho de acceso a la información pública")

[resolution_date]
Fecha de firma de la resolución del consejo de transparencia. Suele aparecer al final del documento, junto a la firma, o en el encabezado. Formato ISO 8601 (YYYY-MM-DD). Null solo si de verdad no aparece.

### [info_request_date] Y [complained_administration] — EXTRACCIÓN CONJUNTA

Estos dos campos aparecen casi SIEMPRE en la misma frase, en el PRIMER PUNTO del apartado «ANTECEDENTES» (o equivalente). Búscalos ahí antes que en cualquier otro sitio.

El patrón habitual es uno de estos (presta atención a las variantes):

- «el reclamante/interesado/solicitante solicitó el [FECHA] al/ante [ADMINISTRACIÓN], al amparo de la Ley 19/2013…»
- «con fecha [FECHA], don/doña [NOMBRE] presentó ante [ADMINISTRACIÓN] solicitud de acceso…»
- «en fecha [FECHA], se presentó solicitud ante [ADMINISTRACIÓN] en la que se pedía…»
- «mediante escrito de [FECHA] dirigido a [ADMINISTRACIÓN], el interesado solicitó…»

**[info_request_date]**: la FECHA en la que el ciudadano presentó la solicitud ORIGINAL a la administración reclamada (NO la fecha de la reclamación posterior ante el consejo de transparencia — esa va en claim_date).

Normaliza la fecha a ISO 8601 (YYYY-MM-DD). Ejemplos de conversión:
- "14 de mayo de 2022" → "2022-05-14"
- "3 de enero de 2024" → "2024-01-03"
- "7-julio-2023" → "2023-07-07"

**[complained_administration]**: el NOMBRE de la administración u organismo a la que el ciudadano dirigió su solicitud original (la «administración reclamada»). Este campo es CRÍTICO: la inmensa mayoría de las resoluciones lo mencionan de forma explícita en la primera frase de los antecedentes.

Reglas estrictas:
- NUNCA devuelvas el nombre del consejo/comisión de transparencia que dicta la resolución (CTBG, Consejo de Transparencia de Aragón, Comissió de Garantia, etc.). Esos son los ÓRGANOS REVISORES, no la administración reclamada.
- Devuelve el nombre más corto y autónomo que identifique al organismo (ej. «Ministerio de Hacienda», «Ayuntamiento de Madrid», «Universidad Complutense de Madrid», «Dirección General de Tráfico»). No le añadas frases como "al amparo de la Ley 19/2013".
- Normaliza la capitalización (sin TODO EN MAYÚSCULAS): «Ministerio de Asuntos Económicos y Transformación Digital», no «MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL».
- Si la solicitud fue trasladada de un organismo a otro, devuelve el organismo ORIGINAL destinatario de la solicitud, no al que fue trasladada.

**No devuelvas null sin intentarlo**: antes de devolver null en cualquiera de estos dos campos, relee con cuidado los primeros tres párrafos del apartado «Antecedentes». En el 95% de los casos la información está ahí. Solo devuelve null si tras esa búsqueda cuidadosa no puedes encontrarlo.

**Ejemplo completo**:
> «I. ANTECEDENTES. 1. Según se desprende del expediente, el reclamante solicitó el 14 de mayo de 2022 al MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL, al amparo de la Ley 19/2013…»

De este fragmento debes extraer:
- `info_request_date`: "2022-05-14"
- `complained_administration`: "Ministerio de Asuntos Económicos y Transformación Digital"

[claim_date]
Fecha de presentación de la RECLAMACIÓN ante el consejo de transparencia (NO la fecha de la solicitud original — esa va en info_request_date). Suele aparecer más abajo en los Antecedentes con frases del tipo «mediante escrito registrado el [FECHA], el interesado interpuso reclamación ante este Consejo…». Formato ISO 8601. Null solo si tras búsqueda cuidadosa no aparece.

[claim_reason]
Una frase CORTA (máximo 120 caracteres) describiendo el MOTIVO por el que el ciudadano reclama. Es la queja concreta del reclamante contra la actuación (o inactuación) de la administración. NO confundir con el asunto de la solicitud — aquí queremos SOLO el motivo de la queja.

Ejemplos típicos (elige el que mejor encaje al caso, o redacta uno equivalente en una sola frase):
- «Silencio administrativo» (cuando la administración simplemente no responde)
- «Denegación total del acceso por aplicación del art. 14.X» (cuando se invoca un límite concreto)
- «Inadmisión a trámite por reelaboración» / «por no ser competente» (cuando se invoca una causa de inadmisión)
- «Acceso parcial insuficiente» (cuando se da parte de la información pero el reclamante considera que falta)
- «Denegación de facto por respuesta evasiva» (cuando responden pero no a lo pedido)
- «Información incompleta o en formato no utilizable»
- «Silencio administrativo tras solicitud de ampliación» (variante de silencio)

Guíate por la queja real del reclamante tal como aparece en los Antecedentes y en la reclamación. Escribe en castellano.

[subject]
Devuelve el asunto de la resolución en castellano. En el caso de que el texto original no sea descriptivo o no sea natural, retorna un texto descriptivo de la solicitud y resultado en menos de 300 caracteres y sin punto al final.

[outcome]
Devuelve el código que mejor describe la decisión final del consejo de transparencia, eligiendo UNO de los siguientes valores:

{$outcomesBlock}

Si la decisión no encaja en NINGUNO de estos códigos, devuelve un texto libre breve (máximo 80 caracteres) describiendo la decisión. Si no puedes determinarla, devuelve null.

[limits]
Lista de LÍMITES al derecho de acceso (art. 14 Ley 19/2013) que la administración reclamada haya alegado para denegar total o parcialmente la información. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguno):

{$limitsBlock}

Solo incluye límites efectivamente alegados por la administración. NO incluyas límites mencionados solo en los fundamentos jurídicos del consejo si no fueron alegados por la administración.

[inadmission_causes]
Lista de CAUSAS DE INADMISIÓN (art. 18 Ley 19/2013) que la administración reclamada haya alegado para inadmitir la solicitud. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguna):

{$causesBlock}

Solo incluye causas efectivamente alegadas por la administración.
PROMPT;
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
    private function buildExtractAnalysisSchema(): array
    {
        $outcomeCodes = array_keys(Resolution::getOutcomeLabels());
        $limitCodes = array_keys(Resolution::getLimitLabels());
        $causeCodes = array_keys(Resolution::getInadmissionCauseLabels());

        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Concise plain-text summary in Spanish, max 400 characters.',
                ],
                'keypoints' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '3-7 key legal reasoning points in Spanish.',
                ],
                'resolution_date' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Resolution signature date in ISO 8601 (YYYY-MM-DD). Null if not found.',
                ],
                'claim_date' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Claim filing date (before the transparency council) in ISO 8601. Null if not found.',
                ],
                'claim_reason' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Short sentence (max 120 chars) describing what the claimant is protesting against (silencio administrativo, a specific art. 14 limit, an art. 18 inadmission cause, partial access, etc.).',
                ],
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
                'subject' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Brief description of the requested information in Spanish, max 300 chars.',
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
                'summary',
                'keypoints',
                'resolution_date',
                'claim_date',
                'claim_reason',
                'info_request_date',
                'complained_administration',
                'subject',
                'outcome',
                'limits',
                'inadmission_causes',
            ],
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

    public static function buildNonCompleteAnalysisPrompt(): string
    {
        $outcomesBlock = self::renderEnumBlock(Resolution::getOutcomeLabels());
        $limitsBlock = self::renderEnumBlock(Resolution::getLimitLabels());
        $causesBlock = self::renderEnumBlock(Resolution::getInadmissionCauseLabels());

        return <<<PROMPT
Actúa como un experto en derecho administrativo español y transparencia. Analiza la resolución adjunta y extrae ÚNICAMENTE los campos indicados más abajo. NO generes resumen, keypoints, asunto ni fechas de resolución/reclamación — esos datos ya existen y no deben tocarse.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

### [info_request_date] Y [complained_administration] — EXTRACCIÓN CONJUNTA

Estos dos campos aparecen casi SIEMPRE en la misma frase, en el PRIMER PUNTO del apartado «ANTECEDENTES» (o equivalente). Búscalos ahí antes que en cualquier otro sitio.

El patrón habitual es uno de estos (presta atención a las variantes):

- «el reclamante/interesado/solicitante solicitó el [FECHA] al/ante [ADMINISTRACIÓN], al amparo de la Ley 19/2013…»
- «con fecha [FECHA], don/doña [NOMBRE] presentó ante [ADMINISTRACIÓN] solicitud de acceso…»
- «en fecha [FECHA], se presentó solicitud ante [ADMINISTRACIÓN] en la que se pedía…»
- «mediante escrito de [FECHA] dirigido a [ADMINISTRACIÓN], el interesado solicitó…»

**[info_request_date]**: la FECHA en la que el ciudadano presentó la solicitud ORIGINAL a la administración reclamada (NO la fecha de la reclamación posterior ante el consejo de transparencia).

Normaliza la fecha a ISO 8601 (YYYY-MM-DD). Ejemplos de conversión:
- "14 de mayo de 2022" → "2022-05-14"
- "3 de enero de 2024" → "2024-01-03"
- "7-julio-2023" → "2023-07-07"

**[complained_administration]**: el NOMBRE de la administración u organismo a la que el ciudadano dirigió su solicitud original (la «administración reclamada»). Este campo es CRÍTICO: la inmensa mayoría de las resoluciones lo mencionan de forma explícita en la primera frase de los antecedentes.

Reglas estrictas:
- NUNCA devuelvas el nombre del consejo/comisión de transparencia que dicta la resolución (CTBG, Consejo de Transparencia de Aragón, Comissió de Garantia, etc.). Esos son los ÓRGANOS REVISORES, no la administración reclamada.
- Devuelve el nombre más corto y autónomo que identifique al organismo (ej. «Ministerio de Hacienda», «Ayuntamiento de Madrid», «Universidad Complutense de Madrid», «Dirección General de Tráfico»). No le añadas frases como "al amparo de la Ley 19/2013".
- Normaliza la capitalización (sin TODO EN MAYÚSCULAS): «Ministerio de Asuntos Económicos y Transformación Digital», no «MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL».
- Si la solicitud fue trasladada de un organismo a otro, devuelve el organismo ORIGINAL destinatario de la solicitud, no al que fue trasladada.

**No devuelvas null sin intentarlo**: antes de devolver null en cualquiera de estos dos campos, relee con cuidado los primeros tres párrafos del apartado «Antecedentes». En el 95% de los casos la información está ahí. Solo devuelve null si tras esa búsqueda cuidadosa no puedes encontrarlo.

**Ejemplo completo**:
> «I. ANTECEDENTES. 1. Según se desprende del expediente, el reclamante solicitó el 14 de mayo de 2022 al MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL, al amparo de la Ley 19/2013…»

De este fragmento debes extraer:
- `info_request_date`: "2022-05-14"
- `complained_administration`: "Ministerio de Asuntos Económicos y Transformación Digital"

[claim_reason]
Una frase CORTA (máximo 120 caracteres) describiendo el MOTIVO por el que el ciudadano reclama. Es la queja concreta del reclamante contra la actuación (o inactuación) de la administración. NO confundir con el asunto — aquí queremos SOLO el motivo de la queja.

Ejemplos típicos (elige el que mejor encaje o redacta uno equivalente en una sola frase):
- «silencio administrativo» (cuando la administración no responde)
- «denegación total del acceso por aplicación del art. 14.X» (cuando se invoca un límite)
- «inadmisión a trámite por reelaboración» / «por no ser competente» (cuando se invoca una causa de inadmisión)
- «acceso parcial insuficiente» (se da parte de la información pero falta lo esencial)
- «denegación de facto por respuesta evasiva» (responden pero no a lo pedido)
- «información incompleta o en formato no utilizable»

Escribe en castellano.

[outcome]
Devuelve el código que mejor describe la decisión final del consejo de transparencia, eligiendo UNO de los siguientes valores:

{$outcomesBlock}

Si la decisión no encaja en NINGUNO de estos códigos, devuelve un texto libre breve (máximo 80 caracteres) describiendo la decisión. Si no puedes determinarla, devuelve null.

[limits]
Lista de LÍMITES al derecho de acceso (art. 14 Ley 19/2013) que la administración reclamada haya alegado para denegar total o parcialmente la información. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguno):

{$limitsBlock}

Solo incluye límites efectivamente alegados por la administración. NO incluyas límites mencionados solo en los fundamentos jurídicos del consejo si no fueron alegados por la administración.

[inadmission_causes]
Lista de CAUSAS DE INADMISIÓN (art. 18 Ley 19/2013) que la administración reclamada haya alegado para inadmitir la solicitud. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguna):

{$causesBlock}

Solo incluye causas efectivamente alegadas por la administración.
PROMPT;
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

        if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
            $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
            $resolution->setDaysToResolve($days);
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
