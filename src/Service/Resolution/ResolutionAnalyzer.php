<?php

namespace App\Service\Resolution;

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

        if ($this->useCustomModel) {
            $jsonSuffix = <<<'SUFFIX'

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{"formatted_text": "HTML formateado aquí"}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;

            return $this->callCustomModelApi($prompt . $jsonSuffix, $cleanedText, ['formatted_text']);
        }

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        $schema = [
            'type' => 'object',
            'properties' => [
                'formatted_text' => [
                    'type' => 'string',
                    'description' => 'Full resolution text translated to Spanish (if needed) and formatted as semantic HTML. DO NOT SUMMARIZE. Must contain all original paragraphs. DO NOT REWRITE THE TEXT, JUST TRANSCRIBE IT, RESPECT THE ORIGINAL.',
                ],
            ],
            'required' => ['formatted_text'],
        ];

        return $this->callGeminiApi($this->smallModel, $parts, $schema, flex: $flex);
    }

    /**
     * Step 2: Extract summary, keypoints, dates and subject (GEMINI_MID_MODEL or custom model).
     *
     * @return array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, subject: ?string}
     */
    public function extractAnalysis(string $cleanedText, bool $flex = false): array
    {
        $prompt = <<<'PROMPT'
Actúa como un experto en derecho administrativo español y transparencia. Analiza la resolución adjunta y extrae la información requerida.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

[summary]
Escribe un resumen directo en texto plano (máximo 400 caracteres).
Explica: 1) Qué se solicitó y a quién. 2) Si se alegó algo 3) Decisión del organismo de transparencia (presta atención a su nombre) y por qué.

[keypoints]
Extrae de 3 a 7 frases completas con los argumentos jurídicos clave (precedentes, argumentación jurídica de los motivos de estimación/desestimación).
Evita que las frases se parezcan demasiado, si se parecen condénsalas en una.
Evita formalidades comunes (por ejemplo, "La ley reconoce el derecho de acceso a la información pública")

[resolution_date] y [claim_date]
Extrae la fecha de firma de la resolución (suele estar al final) y la fecha de presentación de la reclamación (suele estar en Antecedentes).

[subject]
Devuelve el asunto de la resolución en castellano. En el caso de que el texto original no sea descriptivo o no sea natural, retorna un texto descriptivo de la solicitud y resultado en menos de 300 caracteres y sin punto al final.
PROMPT;

        if ($this->useCustomModel) {
            $jsonSuffix = <<<'SUFFIX'

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{"summary": "resumen en texto plano", "keypoints": ["punto 1", "punto 2", ...], "resolution_date": "YYYY-MM-DD o null", "claim_date": "YYYY-MM-DD o null", "subject": "asunto en castellano o null"}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;

            $result = $this->callCustomModelApi($prompt . $jsonSuffix, $cleanedText, ['summary', 'keypoints']);

            $result['resolution_date'] = $result['resolution_date'] ?? null;
            $result['claim_date'] = $result['claim_date'] ?? null;
            $result['subject'] = $result['subject'] ?? null;

            return $result;
        }

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        $schema = [
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
                    'description' => 'Claim filing date in ISO 8601 (YYYY-MM-DD). Null if not found.',
                ],
                'subject' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Brief description of the requested information in Spanish, max 300 chars.',
                ],
            ],
            'required' => ['summary', 'keypoints', 'resolution_date', 'claim_date', 'subject'],
        ];

        $result = $this->callGeminiApi($this->midModel, $parts, $schema, flex: $flex);

        $result['resolution_date'] = $result['resolution_date'] ?? null;
        $result['claim_date'] = $result['claim_date'] ?? null;
        $result['subject'] = $result['subject'] ?? null;

        return $result;
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

Responde ÚNICAMENTE con un JSON válido: un array donde cada elemento tiene {"index": N, "formatted_text": "HTML"}.
Ejemplo: [{"index": 0, "formatted_text": "<h2>..."}, {"index": 1, "formatted_text": "<h2>..."}]
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
PROMPT;

        $userContent = $this->buildBatchUserContent($cleanedTexts);

        return $this->callCustomModelBatchApi($prompt, $userContent, $cleanedTexts, ['formatted_text']);
    }

    /**
     * Batch analyze: send multiple resolution texts in one call, get array of analysis results.
     * Only works with custom model.
     *
     * @param array<int, string> $cleanedTexts Indexed array of cleaned texts
     * @return array<int, array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, subject: ?string}> Results indexed by same keys
     */
    public function batchExtractAnalysis(array $cleanedTexts): array
    {
        $prompt = <<<'PROMPT'
Actúa como un experto en derecho administrativo español y transparencia. Se te proporcionan varios textos de resoluciones, cada uno identificado por un número. Analiza CADA resolución y extrae la información requerida.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

Para CADA resolución extrae:

[summary]
Escribe un resumen directo en texto plano (máximo 400 caracteres).
Explica: 1) Qué se solicitó y a quién. 2) Si se alegó algo 3) Decisión del organismo de transparencia (presta atención a su nombre) y por qué.

[keypoints]
Extrae de 3 a 7 frases completas con los argumentos jurídicos clave (precedentes, argumentación jurídica de los motivos de estimación/desestimación).
Evita que las frases se parezcan demasiado, si se parecen condénsalas en una.
Evita formalidades comunes (por ejemplo, "La ley reconoce el derecho de acceso a la información pública")

[resolution_date] y [claim_date]
Extrae la fecha de firma de la resolución (suele estar al final) y la fecha de presentación de la reclamación (suele estar en Antecedentes).

[subject]
Devuelve el asunto de la resolución en castellano. En el caso de que el texto original no sea descriptivo o no sea natural, retorna un texto descriptivo de la solicitud y resultado en menos de 300 caracteres y sin punto al final.

Responde ÚNICAMENTE con un JSON válido: un array donde cada elemento tiene {"index": N, "summary": "...", "keypoints": [...], "resolution_date": "YYYY-MM-DD o null", "claim_date": "YYYY-MM-DD o null", "subject": "... o null"}.
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
PROMPT;

        $userContent = $this->buildBatchUserContent($cleanedTexts);
        $results = $this->callCustomModelBatchApi($prompt, $userContent, $cleanedTexts, ['summary', 'keypoints']);

        foreach ($results as $i => $result) {
            $results[$i]['resolution_date'] = $result['resolution_date'] ?? null;
            $results[$i]['claim_date'] = $result['claim_date'] ?? null;
            $results[$i]['subject'] = $result['subject'] ?? null;
        }

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
     * @param array<int, string> $cleanedTexts Original indexed texts (for key mapping)
     * @param string[] $requiredKeys Keys each result item must have
     * @return array<int, array<string, mixed>> Results indexed by original keys
     */
    private function callCustomModelBatchApi(string $prompt, string $userContent, array $cleanedTexts, array $requiredKeys, int $maxRetries = 2): array
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
                    'response_format' => ['type' => 'json_object'],
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
     * @return array<string, mixed>
     */
    private function callCustomModelApi(string $prompt, string $text, array $requiredKeys, int $maxRetries = 2): array
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
                    'response_format' => ['type' => 'json_object'],
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
}
