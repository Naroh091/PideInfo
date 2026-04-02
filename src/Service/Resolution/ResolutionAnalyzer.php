<?php

namespace App\Service\Resolution;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionAnalyzer
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_MID_MODEL')]
        private readonly string $geminiModel,
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
     * Call Gemini to format, summarize, extract keypoints and dates from a resolution.
     *
     * @return array{formatted_text: string, summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string}
     */
    public function analyze(string $cleanedText): array
    {
        $prompt = <<<'PROMPT'
Eres un experto en derecho administrativo español y transparencia. Se te proporciona el texto de una resolución de un consejo de transparencia sobre una reclamación de acceso a información pública.

IMPORTANTE: Si el texto está en catalán, gallego, euskera o cualquier otro idioma distinto del castellano, TODA tu salida (formatted_text, summary, keypoints) debe estar traducida al castellano. Traduce el contenido manteniendo la terminología jurídica correcta en castellano.

Tu tarea tiene tres partes:

## 1. FORMATEAR EL TEXTO

Convierte el texto a HTML semántico limpio. Etiquetas permitidas: h2, h3, p, strong, em, ol, li, ul, blockquote, a, cite, br, hr.

Reglas:
- <h2> para secciones principales (ANTECEDENTES, FUNDAMENTOS JURÍDICOS, RESOLUCIÓN)
- <h3> para subsecciones si las hay
- <strong> para términos legales clave, nombres de organismos y referencias normativas
- <em> para citas textuales de solicitudes o alegaciones
- <blockquote> para citas literales extensas (texto de solicitudes, alegaciones, artículos de ley)
- <ol>/<li> donde el texto original tiene listas numeradas
- <cite> para nombres de leyes cuando se mencionan por primera vez
- Elimina artefactos de la extracción PDF (saltos de línea incorrectos dentro de párrafos, espacios extra)
- Reconstruye párrafos completos uniendo líneas que fueron cortadas por el formato PDF
- NO uses etiquetas <html>, <head>, <body> — solo el fragmento de contenido
- NO añadas estilos inline ni clases CSS
- NO inventes ni añadas contenido que no esté en el original
- ELIMINA las URLs sueltas que aparecen como notas a pie de página o referencias bibliográficas del PDF (ej: líneas que solo contienen una URL a boe.es u otros sitios). Estas NO deben aparecer en el HTML resultante, ni como <a> ni como texto. Solo conserva URLs que estén integradas dentro del texto de una cita o alegación del reclamante.
- ELIMINA el bloque de metadatos de la resolución que suele aparecer al principio o al final: "Número y fecha de resolución:", "Número de expediente:", "Reclamante:", "Organismo:", "Sentido de la resolución:", "Palabras clave:". Este bloque ya se extrae por separado y no debe formar parte del texto formateado.
- ELIMINA líneas de tipo "RA CTBG Número: XXXX-XXXX Fecha: XX/XX/XXXX" y líneas de firmante como "JOSE LUIS RODRIGUEZ ALVAREZ (1 de 1) Presidente".

## 2. GENERAR RESUMEN

Escribe un resumen conciso en texto plano (máximo 350-400 caracteres) que explique:
- Qué información se solicitó y a qué organismo
- Cuál fue la decisión del consejo de transparencia y por qué

El resumen NO debe superar los 400 caracteres. Sé directo y evita redundancias.

## 3. EXTRAER PUNTOS CLAVE

Extrae entre 3 y 7 puntos clave de la argumentación jurídica del consejo. Cada punto debe ser una frase completa que resuma un argumento o razonamiento relevante. Céntrate en:
- Los fundamentos jurídicos que sustentan la decisión
- La interpretación de la ley de transparencia aplicada
- Los precedentes citados si los hay
- Las razones específicas de la estimación o desestimación

## 4. EXTRAER FECHA DE RESOLUCIÓN

Busca la fecha de la resolución en el texto. Suele aparecer en la firma final del documento (e.g., "Madrid, 15 de enero de 2025", "En Barcelona, a 3 de febrero de 2026"). Devuélvela en formato ISO 8601 (YYYY-MM-DD). Si no la encuentras, devuelve null.

## 5. EXTRAER FECHA DE RECLAMACIÓN

Busca la fecha en que se presentó la reclamación. Suele mencionarse en los ANTECEDENTES (e.g., "Con fecha 5 de marzo de 2025, tuvo entrada en este Consejo la reclamación...", "La reclamació va ser presentada a la GAIP el dia 10 de febrer de 2026"). Devuélvela en formato ISO 8601 (YYYY-MM-DD). Si no la encuentras, devuelve null.

## 6. EXTRAER ASUNTO

Extrae una descripción breve (máximo 500 caracteres) de la información que se solicitó. Debe estar en castellano. Si el texto original está en otro idioma, tradúcelo. Si no puedes determinar el asunto, devuelve null.

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:
{
  "formatted_text": "HTML formateado",
  "summary": "resumen en texto plano",
  "keypoints": ["punto 1", "punto 2", ...],
  "resolution_date": "YYYY-MM-DD o null",
  "claim_date": "YYYY-MM-DD o null",
  "subject": "asunto en castellano o null"
}
PROMPT;

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        return $this->callGeminiApi($parts);
    }

    private function callGeminiApi(array $parts): array
    {
        $url = sprintf(self::GEMINI_ENDPOINT, $this->geminiModel) . '?key=' . $this->geminiApiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 65536,
                'responseMimeType' => 'application/json',
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf('Gemini API error (HTTP %d): %s', $httpCode, $response));
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new \RuntimeException('Failed to decode Gemini API response.');
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            throw new \RuntimeException('Empty response from Gemini API.');
        }

        $result = json_decode($text, true);
        if (!$result || !isset($result['formatted_text'], $result['summary'], $result['keypoints'])) {
            throw new \RuntimeException('Invalid JSON structure from Gemini: ' . mb_substr($text, 0, 500));
        }

        // Ensure optional fields exist (may be null)
        $result['resolution_date'] = $result['resolution_date'] ?? null;
        $result['claim_date'] = $result['claim_date'] ?? null;
        $result['subject'] = $result['subject'] ?? null;

        return $result;
    }
}
