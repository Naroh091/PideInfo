<?php

namespace App\Service\Resolution;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wraps the Gemini Batch API (generativelanguage.googleapis.com).
 *
 * Workflow:
 *   1. buildFormatLine() – build a JSONL line for one resolution
 *   2. uploadFile()      – upload the assembled JSONL via the Files API
 *   3. createBatch()     – create an async batch job referencing the file
 *   4. getBatch()        – poll job state
 *   5. downloadResults() – retrieve the output JSONL when state = SUCCEEDED
 */
class GeminiBatchService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    /**
     * Prompt for the format-only batch task.
     *
     * Covers: HTML formatting, Spanish translation, date extraction, subject extraction.
     * Deliberately excludes summary and keypoints (those require stronger reasoning).
     */
    public const FORMAT_PROMPT = <<<'PROMPT'
Eres un experto en derecho administrativo español y transparencia. Se te proporciona el texto extraído de un PDF de una resolución de un consejo de transparencia.

IMPORTANTE: Si el texto está en catalán, gallego, euskera o cualquier otro idioma distinto del castellano, TODA tu salida (formatted_text, subject) debe estar traducida al castellano. Traduce manteniendo la terminología jurídica correcta.

Tu tarea tiene CUATRO partes:

## 1. FORMATEAR EL TEXTO

Convierte el texto a HTML semántico limpio. Etiquetas permitidas: h2, h3, p, strong, em, ol, li, ul, blockquote, a, cite, br, hr.

Reglas:
- <h2> para secciones principales (ANTECEDENTES, FUNDAMENTOS JURÍDICOS, RESOLUCIÓN)
- <h3> para subsecciones si las hay
- <strong> para términos legales clave, nombres de organismos y referencias normativas
- <em> para citas textuales de solicitudes o alegaciones
- <blockquote> para citas literales extensas (texto de solicitudes, alegaciones, artículos de ley)
- <ol>/<li> donde el texto original tiene listas numeradas
- <a href="..." target="_blank" rel="noopener"> para URLs de BOE u otras referencias legales
- <cite> para nombres de leyes cuando se mencionan por primera vez
- Elimina artefactos de la extracción PDF (saltos de línea incorrectos dentro de párrafos, espacios extra)
- Reconstruye párrafos completos uniendo líneas que fueron cortadas por el formato PDF
- NO uses etiquetas <html>, <head>, <body> — solo el fragmento de contenido
- NO añadas estilos inline ni clases CSS
- NO inventes ni añadas contenido que no esté en el original

## 2. EXTRAER FECHA DE RESOLUCIÓN

Busca la fecha de la resolución en el texto. Suele aparecer en la firma final del documento (e.g., "Madrid, 15 de enero de 2025", "En Barcelona, a 3 de febrero de 2026", "A Barcelona, 10 de febrer de 2025"). Devuélvela en formato ISO 8601 (YYYY-MM-DD). Si no la encuentras, devuelve null.

## 3. EXTRAER FECHA DE RECLAMACIÓN

Busca la fecha en que se presentó la reclamación. Suele mencionarse en los ANTECEDENTES (e.g., "Con fecha 5 de marzo de 2025, tuvo entrada en este Consejo la reclamación...", "La reclamació va ser presentada a la GAIP el dia 10 de febrer de 2026"). Devuélvela en formato ISO 8601 (YYYY-MM-DD). Si no la encuentras, devuelve null.

## 4. EXTRAER ASUNTO

Extrae una descripción breve (máximo 500 caracteres) de la información que se solicitó. Debe estar en castellano. Si el texto original está en otro idioma, tradúcelo. Si no puedes determinarlo, devuelve null.

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{
  "formatted_text": "HTML formateado",
  "resolution_date": "YYYY-MM-DD o null",
  "claim_date": "YYYY-MM-DD o null",
  "subject": "asunto en castellano o null"
}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
PROMPT;

    /**
     * Prompt for the analyze task.
     *
     * Assumes text has already been formatted (HTML or clean plain text).
     * Reuses the shared individual-analysis prompt from ResolutionAnalyzer so all
     * analysis paths (sync, async, custom-batch, gemini-batch) stay in sync.
     */
    public static function getAnalyzePrompt(): string
    {
        $base = ResolutionAnalyzer::buildExtractAnalysisPrompt();

        return $base . <<<'SUFFIX'


Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{
  "summary": "resumen en texto plano",
  "keypoints": ["punto 1", "punto 2", ...],
  "resolution_date": "YYYY-MM-DD o null",
  "claim_date": "YYYY-MM-DD o null",
  "info_request_date": "YYYY-MM-DD o null",
  "complained_administration": "nombre o null",
  "subject": "asunto o null",
  "outcome": "código del enum o texto libre o null",
  "limits": ["código", "..."],
  "inadmission_causes": ["código", "..."]
}
SÓLO RESPONDE CON EL JSON, SIN NINGÚN OTRO TEXTO.
SUFFIX;
    }

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build a single JSONL line for the format task (Flash Lite, no thinking).
     *
     * @param string $key       Unique key encoding source + reference, e.g. "GAIP:0197/2025"
     * @param string $cleanText Cleaned raw PDF text (use ResolutionAnalyzer::cleanText first)
     */
    public function buildFormatLine(string $key, string $cleanText, bool $flex = false): string
    {
        return $this->buildLine($key, self::FORMAT_PROMPT, $cleanText, thinkingBudget: 0, flex: $flex);
    }

    /**
     * Build a single JSONL line for the analyze task (summary + keypoints, allows thinking).
     *
     * @param string $key  Unique key encoding source + reference, e.g. "GAIP:0197/2025"
     * @param string $text Resolution text — formatted HTML or cleaned plain text
     */
    public function buildAnalyzeLine(string $key, string $text, bool $flex = false): string
    {
        return $this->buildLine($key, self::getAnalyzePrompt(), $text, thinkingBudget: null, flex: $flex);
    }

    private function buildLine(string $key, string $prompt, string $text, ?int $thinkingBudget, bool $flex = false): string
    {
        $generationConfig = [
            'temperature' => 0.1,
            'maxOutputTokens' => 65536,
            'responseMimeType' => 'application/json',
        ];

        if ($thinkingBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $thinkingBudget];
        }

        $request = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt . "\n\n---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $text],
                    ],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];

        if ($flex) {
            $request['service_tier'] = 'FLEX';
        }

        return json_encode(['key' => $key, 'request' => $request], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Upload a JSONL string via the Gemini Files API (resumable upload).
     *
     * @return string File name, e.g. "files/abc123"
     */
    public function uploadFile(string $content, string $displayName): string
    {
        $byteSize = strlen($content);

        // Step 1: Initiate resumable upload session
        $initResponse = $this->httpClient->request('POST', self::UPLOAD_URL, [
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $byteSize,
                'X-Goog-Upload-Header-Content-Type' => 'application/jsonl',
                'Content-Type' => 'application/json',
            ],
            'json' => ['file' => ['display_name' => $displayName]],
            'timeout' => 30,
        ]);

        if ($initResponse->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to start file upload: ' . $initResponse->getContent(false));
        }

        $uploadUrl = $initResponse->getHeaders(false)['x-goog-upload-url'][0] ?? null;
        if (!$uploadUrl) {
            throw new \RuntimeException('No upload URL returned from Gemini Files API');
        }

        // Step 2: Upload content and finalize
        $uploadResponse = $this->httpClient->request('POST', $uploadUrl, [
            'headers' => [
                'Content-Length' => (string) $byteSize,
                'X-Goog-Upload-Command' => 'upload, finalize',
                'X-Goog-Upload-Offset' => '0',
                'Content-Type' => 'application/jsonl',
            ],
            'body' => $content,
            'timeout' => 120,
        ]);

        if ($uploadResponse->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to upload file: ' . $uploadResponse->getContent(false));
        }

        $data = $uploadResponse->toArray();
        $fileName = $data['file']['name'] ?? null;
        if (!$fileName) {
            throw new \RuntimeException('No file name in upload response: ' . json_encode($data));
        }

        $this->logger->info('Uploaded JSONL file', ['name' => $fileName, 'bytes' => $byteSize]);

        return $fileName;
    }

    /**
     * Create a batch job referencing an uploaded JSONL file.
     *
     * @param string $model       Gemini model ID, e.g. "gemini-2.5-flash-lite"
     * @param string $fileName    File name from uploadFile(), e.g. "files/abc123"
     * @param string $displayName Human-readable job label
     * @return string Batch name, e.g. "batches/12345"
     */
    public function createBatch(string $model, string $fileName, string $displayName): string
    {
        $url = sprintf('%s/models/%s:batchGenerateContent?key=%s', self::BASE_URL, $model, $this->apiKey);

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'batch' => [
                    'display_name' => $displayName,
                    'input_config' => ['file_name' => $fileName],
                ],
            ],
            'timeout' => 60,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to create batch job: ' . $response->getContent(false));
        }

        $data = $response->toArray();
        $batchName = $data['name'] ?? null;
        if (!$batchName) {
            throw new \RuntimeException('No batch name in response: ' . json_encode($data));
        }

        $this->logger->info('Created batch job', ['name' => $batchName, 'model' => $model]);

        return $batchName;
    }

    /**
     * Get current state of a batch job.
     *
     * @param string $batchName e.g. "batches/12345"
     * @return array Full batch object with 'state', 'dest', etc.
     */
    public function getBatch(string $batchName): array
    {
        $url = sprintf('%s/%s?key=%s', self::BASE_URL, $batchName, $this->apiKey);

        $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to get batch status: ' . $response->getContent(false));
        }

        return $response->toArray();
    }

    /**
     * Cancel a batch job.
     *
     * @param string $batchName e.g. "batches/12345"
     */
    public function cancelBatch(string $batchName): void
    {
        $url = sprintf('%s/%s:cancel?key=%s', self::BASE_URL, $batchName, $this->apiKey);

        $response = $this->httpClient->request('POST', $url, ['timeout' => 30]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to cancel batch: ' . $response->getContent(false));
        }

        $this->logger->info('Cancelled batch job', ['name' => $batchName]);
    }

    /**
     * Download the output JSONL file when a batch job has succeeded.
     *
     * @param string $resultFileName e.g. "files/result-abc123"
     * @return string Raw JSONL content
     */
    public function downloadResults(string $resultFileName): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/download/v1beta/%s:download?alt=media&key=%s',
            $resultFileName,
            $this->apiKey
        );

        $response = $this->httpClient->request('GET', $url, ['timeout' => 300]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to download batch results: ' . $response->getContent(false));
        }

        return $response->getContent();
    }
}
