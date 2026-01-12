<?php

namespace App\Service\AI;

use App\Entity\Document;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DocumentAnalyzer
{
    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
    ) {
    }

    /**
     * Analyze a document using Gemini AI and extract relevant information
     * @return array<string, mixed>
     */
    public function analyze(Document $document): array
    {
        return $this->analyzeMultiple([$document]);
    }

    /**
     * Analyze multiple related documents together for better context
     * @param Document[] $documents
     * @return array<string, mixed>
     */
    public function analyzeMultiple(array $documents): array
    {
        if (empty($documents)) {
            throw new \InvalidArgumentException('At least one document is required');
        }

        $parts = [];

        // Add each document as a separate part
        foreach ($documents as $index => $document) {
            $content = $this->documentsStorage->read($document->getStoredFilename());
            $mimeType = $document->getMimeType();
            $base64Content = base64_encode($content);

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $base64Content,
                ],
            ];

            // Add context about this document
            $parts[] = [
                'text' => sprintf('[Documento %d: %s]', $index + 1, $document->getOriginalFilename()),
            ];
        }

        // Add the analysis prompt
        $prompt = count($documents) > 1
            ? $this->buildMultiDocumentPrompt(count($documents))
            : $this->buildPrompt();

        $parts[] = ['text' => $prompt];

        $response = $this->callGeminiApiWithParts($parts);

        return $this->parseResponse($response);
    }

    private function buildMultiDocumentPrompt(int $documentCount): string
    {
        return <<<PROMPT
Tienes {$documentCount} documentos relacionados con la MISMA solicitud de acceso a información pública en España.
Pueden incluir: la solicitud original, el justificante de registro, acuses de recibo, resoluciones, etc.

Analiza TODOS los documentos juntos para extraer la información más completa y precisa.
Si un dato aparece en un documento pero no en otro, usa el que tengas.
El justificante de registro suele tener el número de registro y la administración correcta.

Extrae la siguiente información en formato JSON:

{
    "documentType": "tipo del documento PRINCIPAL (uno de: solicitud, acuse_recibo, resolucion, notificacion, prorroga, resolucion_ctbg, otro)",
    "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
    "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
    "documentDate": "fecha del documento o registro en formato YYYY-MM-DD",
    "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
    "summary": "resumen breve del contenido (máximo 200 caracteres)",
    "status": "estado que indica el documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
    "isExtension": "true si es una notificación de prórroga, false en caso contrario",
    "extensionDays": "número de días de prórroga (si aplica, null si no)",
    "newDeadlineDate": "nueva fecha límite si se menciona explícitamente en formato YYYY-MM-DD (null si no)",
    "denialReason": "motivo de denegación si el documento es una resolución denegatoria (null si no aplica)",
    "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018'). NO uses 'Solicitud de acceso a información pública'.",
    "requestDescription": "descripción detallada de la información solicitada"
}

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
- PRIORIZA lo que aparece en el justificante de registro electrónico

Ejemplos:
- "Registro Electrónico del Principado de Asturias" + Hospital → "Principado de Asturias"
- "Canal de Isabel II" → "Canal de Isabel II" (tiene portal propio)
- "RTVE" → "RTVE" (tiene portal propio)
- "Área Sanitaria" sin registro propio → usar la CCAA correspondiente

REGLAS PARA applicableLaw:
- SOLO incluye la ley de transparencia aplicable
- Para solicitudes estatales: "Ley 19/2013"
- Para Asturias: "Ley 8/2018 del Principado de Asturias"
- NO incluyas otras leyes mencionadas (contratos, procedimiento, etc.)

IMPORTANTE:
- Responde SOLO con el JSON, sin texto adicional
- Si no puedes determinar un campo, usa null
- Las fechas deben estar en formato YYYY-MM-DD
PROMPT;
    }

    private function buildPrompt(): string
    {
        return <<<PROMPT
Analiza este documento relacionado con una solicitud de acceso a información pública en España (Ley 19/2013 o leyes autonómicas de transparencia).

Extrae la siguiente información en formato JSON:

{
    "documentType": "tipo de documento (uno de: solicitud, acuse_recibo, resolucion, notificacion, prorroga, resolucion_ctbg, otro)",
    "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
    "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
    "documentDate": "fecha del documento en formato YYYY-MM-DD",
    "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
    "summary": "resumen breve del contenido (máximo 200 caracteres)",
    "status": "estado que indica el documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
    "isExtension": "true si es una notificación de prórroga, false en caso contrario",
    "extensionDays": "número de días de prórroga (si aplica, null si no)",
    "newDeadlineDate": "nueva fecha límite si se menciona explícitamente en formato YYYY-MM-DD (null si no)",
    "denialReason": "motivo de denegación si el documento es una resolución denegatoria (null si no aplica)",
    "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018', 'Gastos publicidad Ayuntamiento 2023'). NO uses 'Solicitud de acceso a información pública'.",
    "requestDescription": "descripción detallada de la información solicitada"
}

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
- Busca pistas en: registro electrónico, cabecera oficial, sello, pie de página
- PRIORIZA lo que aparece en el justificante de registro electrónico

Ejemplos:
- "Registro Electrónico del Principado de Asturias" + Hospital → "Principado de Asturias" (el hospital no tiene portal propio)
- "Canal de Isabel II" → "Canal de Isabel II" (tiene portal de transparencia propio)
- "RTVE" → "RTVE" (tiene portal propio)
- "Ministerio de Sanidad" → "Ministerio de Sanidad"
- "Ayuntamiento de Madrid" → "Ayuntamiento de Madrid"
- "Área Sanitaria" sin registro propio → usar la CCAA correspondiente

REGLAS PARA applicableLaw:
- SOLO incluye la ley de transparencia aplicable
- Para solicitudes estatales: "Ley 19/2013"
- Para Asturias: "Ley 8/2018 del Principado de Asturias"
- NO incluyas otras leyes mencionadas (contratos, procedimiento, etc.)

IMPORTANTE:
- Responde SOLO con el JSON, sin texto adicional
- Si no puedes determinar un campo, usa null
- Para documentType usa exactamente uno de los valores indicados
- Las fechas deben estar en formato YYYY-MM-DD
PROMPT;
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function callGeminiApiWithParts(array $parts): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $this->geminiApiKey;

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
                'maxOutputTokens' => 2048,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error: ' . $response);
        }

        return json_decode($response, true);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function parseResponse(array $response): array
    {
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Clean up the response - remove markdown code blocks if present
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse Gemini response as JSON: ' . $text);
        }

        // Map document type to our constants
        $typeMap = [
            'solicitud' => Document::TYPE_REQUEST,
            'acuse_recibo' => Document::TYPE_RECEIPT,
            'resolucion' => Document::TYPE_RESPONSE,
            'notificacion' => Document::TYPE_OTHER,
            'prorroga' => Document::TYPE_EXTENSION,
            'resolucion_ctbg' => Document::TYPE_COMPLAINT_RESOLUTION,
            'otro' => Document::TYPE_OTHER,
        ];

        $data['documentType'] = $typeMap[$data['documentType'] ?? 'otro'] ?? Document::TYPE_OTHER;

        return $data;
    }
}
