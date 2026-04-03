<?php

namespace App\Service\Resolution;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionAnalyzer
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $smallModel,
        #[Autowire(env: 'GEMINI_FREE_MODEL')]
        private readonly string $freeModel,
        private readonly LoggerInterface $logger,
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

        // Ensure double newlines before well-known section headings and numbered items.
        // Some sources (e.g. CVAIP .docx) only produce single newlines between paragraphs.
        $sectionPatterns = [
            'ANTECEDENTES(?:\s+DE\s+HECHO)?',
            'FUNDAMENTOS(?:\s+(?:JURÍDICOS|DE\s+DERECHO))?',
            'RESOLUCI[ÓO]N|RESUELVE',
            'VISTOS',
            '\d+\.\-\s',
            '(?:Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno|Décimo|Único)\.\-',
        ];
        $text = preg_replace(
            '/\n(?=' . implode('|', $sectionPatterns) . ')/u',
            "\n\n",
            $text
        );

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
    public function analyze(string $cleanedText, bool $useOperations = false): array
    {
        $formatted = $useOperations
            ? $this->formatTextWithOperations($cleanedText)
            : $this->formatText($cleanedText);
        $analysis = $this->extractAnalysis($cleanedText);

        return array_merge($formatted, $analysis);
    }

    /**
     * Step 1: Format resolution text to semantic HTML (GEMINI_SMALL_MODEL).
     *
     * @return array{formatted_text: string}
     */
    private function formatText(string $cleanedText): array
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

        return $this->callGeminiApi($this->smallModel, $parts, $schema);
    }

    /**
     * Step 2: Extract summary, keypoints, dates and subject (GEMINI_FREE_MODEL).
     *
     * @return array{summary: string, keypoints: string[], resolution_date: ?string, claim_date: ?string, subject: ?string}
     */
    private function extractAnalysis(string $cleanedText): array
    {
        $prompt = <<<'PROMPT'
Actúa como un experto en derecho administrativo español y transparencia. Analiza la resolución adjunta y extrae la información requerida.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

[summary]
- Escribe un resumen directo en texto plano (máximo 400 caracteres).
- Explica: 1) Qué se solicitó y a quién. 2) Si se alegó algo 3) Decisión del Consejo y por qué.

[keypoints]
- Extrae de 3 a 7 frases completas con los argumentos jurídicos clave (ley aplicada, precedentes, motivos de estimación/desestimación).
- Omite formalidades obvias (ej. "El Consejo es competente").

[resolution_date] y [claim_date]
- Extrae la fecha de firma de la resolución (suele estar al final) y la fecha de presentación de la reclamación (suele estar en Antecedentes).

[subject]
- Devuelve el asunto de la resolución en castellano. En el caso de que el texto original no sea descriptivo o no sea natural, retorna un texto descriptivo de la solicitud y resultado en menos de 300 caracteres y sin punto al final.
PROMPT;

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

        $result = $this->callGeminiApi($this->freeModel, $parts, $schema);

        $result['resolution_date'] = $result['resolution_date'] ?? null;
        $result['claim_date'] = $result['claim_date'] ?? null;
        $result['subject'] = $result['subject'] ?? null;

        return $result;
    }

    /**
     * Alternative Step 1: Return compact formatting operations instead of full HTML.
     * Uses ~68% fewer output tokens than formatText().
     *
     * @return array{formatted_text: string, resolution_date: ?string, claim_date: ?string, subject: ?string}
     */
    private function formatTextWithOperations(string $cleanedText): array
    {
        $prompt = <<<'PROMPT'
Eres un experto en derecho administrativo español. Analiza el texto de la resolución adjunta y genera INSTRUCCIONES DE FORMATO en JSON.

NO devuelvas el texto completo. Solo devuelve operaciones que describan qué cambios aplicar al texto original para convertirlo en HTML semántico.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma cooficial (ya sea el texto principal O citas/fragmentos extensos incrustados), indica "needs_translation": true. En ese caso, NO generes operaciones de formato — solo indica que necesita traducción. IMPORTANTE: resoluciones del País Vasco (CVAIP) frecuentemente contienen citas textuales en euskera dentro de un texto principal en castellano — en ese caso también debes indicar needs_translation: true.

OPERACIONES DISPONIBLES:

1. headings: Títulos de sección y subsección.
   - level 2: secciones principales: ANTECEDENTES, FUNDAMENTOS JURÍDICOS, RESOLUCIÓN/RESUELVE y similares
   - level 3: subsecciones numeradas: "Primero.-", "Segundo.-", "Tercero.-", "Único.-", "1.-", "2.-", etc.
   - SOLO level 2 o 3 (nunca 1)
   - "text": SOLO el encabezado, no el contenido que sigue. Ejemplos correctos:
     "I. ANTECEDENTES" (level 2)
     "Primero.-" (level 3)
     "RESUELVE" (level 2)
   - IMPORTANTE: "Primero.-", "Segundo.-", etc. son SUBSECCIONES (h3), NUNCA elementos de lista

2. bold: Términos para <strong>. Cada uno debe:
   - Existir LITERALMENTE en el texto (copia y pega exacto)
   - Ser CORTO (máximo ~40 caracteres): nombres de organismos, siglas, términos clave
   - NO repetir lo que ya esté en cites
   - Ejemplos: "Consejería de Sanidad", "LTAIBG", "Tribunal Supremo", "D.ª XXX"

3. italic: Frases para <em>. Citas textuales CORTAS de solicitudes o alegaciones.

4. blockquotes: Bloques de texto extenso entrecomillado que deben ir en <blockquote>.
   - Incluye TODO pasaje largo entre comillas ("…") que ocupe más de 2 líneas
   - start_text: primeras ~80 caracteres del bloque, copiados EXACTAMENTE del texto original (incluyendo la comilla de apertura si la tiene)
   - end_text: últimos ~80 caracteres del bloque, copiados EXACTAMENTE (incluyendo la comilla de cierre si la tiene)
   - Incluye citas de sentencias, artículos de ley, texto de solicitudes/alegaciones, informes

5. cites: Primera mención completa de cada ley/decreto/norma para <cite>.
   - Texto exacto tal como aparece en el documento
   - Solo la PRIMERA mención de cada norma

6. lists: SOLO para listas cortas de elementos enumerados dentro de un párrafo.
   - Ejemplo correcto: "a) Especialidad médica. b) Centro de origen. c) Centro de destino."
   - NUNCA uses lists para subsecciones (Primero.-, Segundo.-, 1.-, 2.-)
   - NUNCA uses lists para párrafos largos que empiezan con numeración
   - Si dudas, NO lo incluyas como lista

7. deletes: Metadatos, firmas, cabeceras, URLs sueltas, pies de página a eliminar.

8. joins: Palabras cortadas por guion al final de línea en el PDF.

REGLAS:
- Los campos de texto deben coincidir EXACTAMENTE con el texto original
- Los párrafos se detectan automáticamente por líneas en blanco — no necesitas indicarlos
- No incluyas en bold los mismos textos que ya están en cites
- Prioriza blockquotes: cualquier texto extenso entrecomillado debe ser blockquote
PROMPT;

        $parts = [
            ['text' => $prompt],
            ['text' => "---\n\nTEXTO DE LA RESOLUCIÓN:\n\n" . $cleanedText],
        ];

        $schema = [
            'type' => 'object',
            'properties' => [
                'needs_translation' => [
                    'type' => 'boolean',
                    'description' => 'True if the text (or substantial quoted sections within it) is not in Spanish and needs translation. Also true for mixed-language documents (e.g. Spanish text with Basque/Catalan/Galician quotes).',
                ],
                'headings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'level' => ['type' => 'integer'],
                            'text' => ['type' => 'string'],
                        ],
                        'required' => ['level', 'text'],
                    ],
                ],
                'bold' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'italic' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'blockquotes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'start_text' => ['type' => 'string'],
                            'end_text' => ['type' => 'string'],
                        ],
                        'required' => ['start_text', 'end_text'],
                    ],
                ],
                'cites' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'lists' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'start_text' => ['type' => 'string'],
                            'end_text' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'start_text', 'end_text'],
                    ],
                ],
                'deletes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'joins' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'first' => ['type' => 'string'],
                            'second' => ['type' => 'string'],
                            'result' => ['type' => 'string'],
                        ],
                        'required' => ['first', 'second', 'result'],
                    ],
                ],
                'resolution_date' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Resolution date YYYY-MM-DD or null.',
                ],
                'claim_date' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Claim date YYYY-MM-DD or null.',
                ],
                'subject' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Brief description of the requested information, max 500 chars.',
                ],
            ],
            'required' => ['needs_translation', 'headings', 'bold', 'italic', 'blockquotes', 'cites', 'lists', 'deletes', 'joins', 'resolution_date', 'claim_date', 'subject'],
        ];

        $ops = $this->callGeminiApi($this->smallModel, $parts, $schema);

        // If the text needs translation, fall back to the full-output approach
        if (!empty($ops['needs_translation'])) {
            $this->logger->info('Operations approach: text needs translation, falling back to full output.');
            return $this->formatText($cleanedText);
        }

        $formattedText = $this->applyOperations($cleanedText, $ops);

        return [
            'formatted_text' => $formattedText,
            'resolution_date' => $ops['resolution_date'] ?? null,
            'claim_date' => $ops['claim_date'] ?? null,
            'subject' => $ops['subject'] ?? null,
        ];
    }

    /**
     * Apply formatting operations to plain text and produce semantic HTML.
     */
    public function applyOperations(string $text, array $ops): string
    {
        // Phase 1: Pre-processing
        foreach ($ops['joins'] ?? [] as $join) {
            $pattern = '/' . preg_quote($join['first'], '/') . '\s*\n\s*' . preg_quote($join['second'], '/') . '/u';
            $text = preg_replace($pattern, $join['result'], $text) ?? $text;
        }

        foreach ($ops['deletes'] ?? [] as $delete) {
            if ($delete !== '' && str_contains($text, $delete)) {
                $text = str_replace($delete, '', $text);
            }
        }

        // Phase 2: Split into paragraphs
        $paragraphs = preg_split('/\n{2,}/', trim($text));
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== ''));

        // Build lookup structures (clamp heading levels to 2-3)
        $headings = [];
        foreach ($ops['headings'] ?? [] as $h) {
            $level = max(2, min(3, (int) $h['level']));
            $headings[] = ['text' => trim($h['text']), 'level' => $level];
        }

        // If text has very few paragraphs but is long, try to split on heading patterns
        // This handles PDFs that lack proper double-newline separators
        if (count($paragraphs) <= 3 && mb_strlen($text) > 3000) {
            $paragraphs = $this->splitOnHeadingPatterns($text, $headings);
        }

        // Phase 3: Structural formatting
        $parts = [];
        $i = 0;
        while ($i < count($paragraphs)) {
            $p = $paragraphs[$i];
            $pNorm = self::normalize($p);

            // Check headings (exact match or prefix match)
            $headingMatch = $this->matchHeading($pNorm, $headings);
            if ($headingMatch !== null) {
                [$hText, $hLevel] = $headingMatch;
                $hEscaped = htmlspecialchars($hText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $parts[] = "<h{$hLevel}>{$hEscaped}</h{$hLevel}>";

                // If heading is a prefix, emit remainder as <p>
                $hNorm = self::normalize($hText);
                if ($hNorm !== $pNorm) {
                    $remainder = $this->removePrefix($p, $hText);
                    $remainder = preg_replace('/^[\.\-–—\s]+/u', '', $remainder);
                    $remainder = trim($remainder);
                    if ($remainder !== '') {
                        $joined = preg_replace('/\n(?!\n)/', ' ', $remainder);
                        $joined = preg_replace('/  +/', ' ', $joined);
                        $parts[] = '<p>' . htmlspecialchars($joined, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                    }
                }
                $i++;
                continue;
            }

            // Check blockquotes
            $bqMatch = $this->matchBlockquote($paragraphs, $i, $ops['blockquotes'] ?? []);
            if ($bqMatch !== null) {
                [$bqEnd] = $bqMatch;
                $bqText = implode("\n\n", array_slice($paragraphs, $i, $bqEnd - $i + 1));
                $joined = preg_replace('/\n(?!\n)/', ' ', $bqText);
                $joined = preg_replace('/  +/', ' ', $joined);
                $parts[] = '<blockquote><p>' . htmlspecialchars($joined, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></blockquote>';
                $i = $bqEnd + 1;
                continue;
            }

            // Check lists
            $listMatch = $this->matchList($paragraphs, $i, $ops['lists'] ?? []);
            if ($listMatch !== null) {
                [$listEnd, $listType] = $listMatch;
                $listText = implode("\n\n", array_slice($paragraphs, $i, $listEnd - $i + 1));
                $parts[] = $this->formatList($listText, $listType);
                $i = $listEnd + 1;
                continue;
            }

            // Default: <p>
            $joined = preg_replace('/\n(?!\n)/', ' ', $p);
            $joined = preg_replace('/  +/', ' ', $joined);
            $parts[] = '<p>' . htmlspecialchars(trim($joined), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            $i++;
        }

        $html = implode("\n", $parts);

        // Phase 4: Inline formatting (cite first, then bold, then italic)
        // Deduplicate and normalize: LLM often returns the same term multiple times
        $cites = array_unique($ops['cites'] ?? []);
        $bold = array_unique($ops['bold'] ?? []);
        $italic = array_unique($ops['italic'] ?? []);

        // Remove bold entries that overlap with cites (cite already wraps them)
        $citesNormalized = array_map([self::class, 'normalize'], $cites);
        $bold = array_filter($bold, function ($b) use ($citesNormalized) {
            $bNorm = self::normalize($b);
            foreach ($citesNormalized as $cNorm) {
                if ($bNorm === $cNorm || str_contains($cNorm, $bNorm)) {
                    return false;
                }
            }

            return true;
        });

        $placeholders = [];

        foreach ($cites as $cite) {
            $html = $this->inlineReplace($html, $cite, 'cite', $placeholders, true);
        }
        foreach ($bold as $b) {
            $html = $this->inlineReplace($html, $b, 'strong', $placeholders);
        }
        foreach ($italic as $it) {
            $html = $this->inlineReplace($html, $it, 'em', $placeholders);
        }

        // Expand placeholders
        foreach ($placeholders as $pid => $replacement) {
            $html = str_replace($pid, $replacement, $html);
        }

        return $html;
    }

    /**
     * Split a long text block into paragraphs using heading patterns when
     * the text lacks proper double-newline separators (common in some PDFs).
     *
     * @return list<string>
     */
    private function splitOnHeadingPatterns(string $text, array $headings): array
    {
        // Build regex pattern from known heading texts + common section patterns
        $patterns = [];
        foreach ($headings as $h) {
            $patterns[] = preg_quote(trim($h['text']), '/');
        }
        // Common Spanish legal section patterns
        $patterns[] = 'ANTECEDENTES(?:\s+DE\s+HECHO)?';
        $patterns[] = 'FUNDAMENTOS(?:\s+(?:JURÍDICOS|DE\s+DERECHO))?';
        $patterns[] = 'RESOLUCI[ÓO]N|RESUELVE';
        $patterns[] = '(?:Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno|Décimo|Único)\s*[\.\-]';
        $patterns[] = '\d+\s*[\.\-]\s*(?=[A-ZÁÉÍÓÚÑ])';

        $splitPattern = '/(?<=\.)\s+(?=' . implode('|', $patterns) . ')/u';

        $blocks = preg_split($splitPattern, $text);
        if ($blocks === false || count($blocks) <= 1) {
            // Fallback: split on sentence-ending patterns before uppercase starts
            $blocks = preg_split('/(?<=[\.\"])\s+(?=[A-ZÁÉÍÓÚÑ0-9])/u', $text);
        }

        return array_values(array_filter(array_map('trim', $blocks ?: [$text]), fn ($p) => $p !== ''));
    }

    private static function normalize(string $s): string
    {
        $s = str_replace(["\u{201c}", "\u{201d}", "\u{00ab}", "\u{00bb}"], '"', $s);
        $s = str_replace(["\u{2018}", "\u{2019}"], "'", $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    /**
     * @return array{string, int}|null [headingText, level]
     */
    private function matchHeading(string $pNorm, array $headings): ?array
    {
        foreach ($headings as $h) {
            $hNorm = self::normalize($h['text']);
            if ($hNorm === $pNorm || str_starts_with($pNorm, $hNorm)) {
                return [$h['text'], $h['level']];
            }
        }

        return null;
    }

    private function removePrefix(string $text, string $prefix): string
    {
        $prefixNorm = self::normalize($prefix);
        $lines = explode("\n", $text);
        $accumulated = '';
        foreach ($lines as $i => $line) {
            $accumulated .= ($accumulated !== '' ? ' ' : '') . trim($line);
            if (str_starts_with(self::normalize($accumulated), $prefixNorm)) {
                $remaining = array_slice($lines, $i + 1);
                $after = mb_substr($accumulated, mb_strlen($prefixNorm));
                $after = trim($after);
                if ($after !== '') {
                    return $after . ($remaining ? "\n" . implode("\n", $remaining) : '');
                }

                return implode("\n", $remaining);
            }
        }

        return $text;
    }

    /**
     * @return array{int}|null [endIndex]
     */
    private function matchBlockquote(array $paragraphs, int $start, array $blockquotes): ?array
    {
        foreach ($blockquotes as $bq) {
            $startText = self::normalize($bq['start_text'] ?? '');
            $endText = self::normalize($bq['end_text'] ?? '');
            if ($startText === '') {
                continue;
            }

            if (!str_contains(self::normalize($paragraphs[$start]), $startText)) {
                continue;
            }

            for ($j = $start; $j < count($paragraphs); $j++) {
                if (str_contains(self::normalize($paragraphs[$j]), $endText)) {
                    return [$j];
                }
            }

            return [$start];
        }

        return null;
    }

    /**
     * @return array{int, string}|null [endIndex, listType]
     */
    private function matchList(array $paragraphs, int $start, array $lists): ?array
    {
        foreach ($lists as $list) {
            $startText = self::normalize($list['start_text'] ?? '');
            if ($startText === '' || !str_contains(self::normalize($paragraphs[$start]), $startText)) {
                continue;
            }

            $endText = self::normalize($list['end_text'] ?? '');
            $type = $list['type'] ?? 'unordered';

            for ($j = $start; $j < count($paragraphs); $j++) {
                if (str_contains(self::normalize($paragraphs[$j]), $endText)) {
                    return [$j, $type];
                }
            }

            return [$start, $type];
        }

        return null;
    }

    private function formatList(string $text, string $type): string
    {
        $tag = $type === 'ordered' ? 'ol' : 'ul';

        // First: join PDF-broken lines within the text (single newlines → spaces)
        $text = preg_replace('/\n(?!\n)/', ' ', $text);
        $text = preg_replace('/  +/', ' ', $text);

        // Split on list item markers: "a) ", "b) ", "1. ", "2. ", etc.
        // preceded by sentence-ending punctuation or start of text
        $items = preg_split('/(?:^|(?<=\.)\s+)(?=\d+[\.\)]\s)/u', $text);
        if (count($items) < 2) {
            $items = preg_split('/(?:^|(?<=\.)\s+)(?=[a-z]\)\s)/u', $text);
        }
        if (count($items) < 2) {
            // Try splitting on " - " (dash-separated items)
            $items = preg_split('/(?:^|\s)(?=-\s)/u', $text);
        }
        if (count($items) < 2) {
            // Last resort: treat the whole block as a single item
            $items = [$text];
        }

        $liParts = [];
        foreach ($items as $item) {
            $cleaned = preg_replace('/^\s*(\d+[\.\)]|[a-z]\)|-)\s*/u', '', trim($item));
            if ($cleaned !== '') {
                $liParts[] = '  <li>' . htmlspecialchars(trim($cleaned), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
        }

        if (count($liParts) === 0) {
            // Fallback: just wrap in a paragraph instead of an empty list
            return '<p>' . htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return "<{$tag}>\n" . implode("\n", $liParts) . "\n</{$tag}>";
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function inlineReplace(string $html, string $target, string $tag, array &$placeholders, bool $firstOnly = false): string
    {
        $escaped = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Direct match
        if (str_contains($html, $escaped)) {
            $pid = '__PH_' . bin2hex(random_bytes(4)) . '__';
            $placeholders[$pid] = "<{$tag}>{$escaped}</{$tag}>";
            $html = $firstOnly
                ? $this->strReplaceFirst($escaped, $pid, $html)
                : str_replace($escaped, $pid, $html);

            return $html;
        }

        // Normalized match (whitespace differences from PDF line joins)
        $normalizedTarget = self::normalize($target);
        $escapedNorm = htmlspecialchars($normalizedTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($escapedNorm !== $escaped && str_contains($html, $escapedNorm)) {
            $pid = '__PH_' . bin2hex(random_bytes(4)) . '__';
            $placeholders[$pid] = "<{$tag}>{$escapedNorm}</{$tag}>";
            $html = $firstOnly
                ? $this->strReplaceFirst($escapedNorm, $pid, $html)
                : str_replace($escapedNorm, $pid, $html);

            return $html;
        }

        // Partial match: use first 40 chars as anchor
        if (mb_strlen($target) > 40) {
            $short = htmlspecialchars(mb_substr($target, 0, 40), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (str_contains($html, $short)) {
                $pid = '__PH_' . bin2hex(random_bytes(4)) . '__';
                $placeholders[$pid] = "<{$tag}>{$short}</{$tag}>";
                $html = $this->strReplaceFirst($short, $pid, $html);

                return $html;
            }
        }

        $this->logger->debug('Operations: inline match failed', ['tag' => $tag, 'target' => mb_substr($target, 0, 60)]);

        return $html;
    }

    private function strReplaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }

        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    private function callGeminiApi(string $model, array $parts, array $schema): array
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
                'topK' => 1,
                'topP' => 1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
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

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error during Gemini API call: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf('Gemini API error (HTTP %d, model %s): %s', $httpCode, $model, $response));
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode Gemini API response: ' . json_last_error_msg());
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            throw new \RuntimeException(sprintf('Empty response from Gemini API (model %s).', $model));
        }

        // Strip markdown code fences that free models (Gemma) sometimes wrap around JSON
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?\s*```$/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $result = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid Gemini response', [
                'model' => $model,
                'http_code' => $httpCode,
                'raw_response' => $text,
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException(sprintf('Invalid JSON from Gemini (model %s).', $model));
        }

        return $result;
    }
}
