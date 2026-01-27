<?php

namespace App\Service\AI;

use App\Entity\Document;
use App\Enum\DocumentType;
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
    "documentType": "tipo del documento PRINCIPAL (uno de: solicitud, acuse_recibo, inicio_tramitacion, resolucion, notificacion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_ctbg, otro)",
    "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
    "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
    "autonomousCommunityCode": "código de la CCAA a la que pertenece la administración (ver tabla abajo, null si es estatal)",
    "documentDate": "fecha del documento o registro en formato YYYY-MM-DD",
    "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
    "summary": "resumen breve del contenido (máximo 200 caracteres)",
    "status": "estado que indica el documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
    "isExtension": "true si es una notificación de prórroga, false en caso contrario",
    "extensionDays": "número de días de prórroga (si aplica, null si no)",
    "newDeadlineDate": "nueva fecha límite si se menciona explícitamente en formato YYYY-MM-DD (null si no)",
    "denialReason": "motivo de denegación si el documento es una resolución denegatoria (null si no aplica)",
    "isRedirection": "true si el documento comunica que la solicitud se traslada/redirige a otro órgano porque la información no obra en poder del órgano original (art. 19.1 Ley 19/2013), false en caso contrario",
    "redirectedToPublicBody": "nombre COMPLETO del órgano al que se traslada, incluyendo el gobierno al que pertenece (ver reglas abajo)",
    "isThirdPartyRights": "true si el documento notifica que la solicitud afecta a derechos de terceros y se abre plazo de alegaciones (art. 19.3 Ley 19/2013), false en caso contrario",
    "thirdPartyAllegationsDeadline": "fecha límite para alegaciones de terceros en formato YYYY-MM-DD (si se menciona, null si no)",
    "isProcessingStart": "true si es un documento de comienzo/inicio de tramitación que notifica el inicio del plazo de 1 mes para resolver (art. 20.1 Ley 19/2013), false en caso contrario",
    "processingStartDate": "fecha a partir de la cual comienza el cómputo del plazo en formato YYYY-MM-DD (si isProcessingStart es true)",
    "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018'). NO uses 'Solicitud de acceso a información pública'.",
    "requestDescription": "descripción detallada de la información solicitada"
}

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
- USA TU CONOCIMIENTO de la administración española para elegir el nivel correcto

NIVEL CORRECTO - ni demasiado genérico ni demasiado específico:
- "Administración General del Estado" es DEMASIADO GENÉRICO → busca el organismo destinatario real (Adif, AENA, Ministerio concreto, etc.)
- "Consejería de X" sin más contexto es DEMASIADO GENÉRICO → usa el nombre de la CCAA (Principado de Asturias, Junta de Andalucía, etc.)
- Entidades con personalidad jurídica propia (Adif, RTVE, Canal de Isabel II, AENA, universidades) → usar su nombre directamente

En justificantes de registro electrónico:
- Si "Oficina de registro" es "Administración General del Estado", mira "Organismo destinatario" para el órgano real
- Si "Oficina de registro" es de una CCAA (ej: "Principado de Asturias"), usa el nombre de la CCAA

Ejemplos:
- Registro "Administración General del Estado" + Organismo destinatario "Adif" → "Adif"
- Registro "Administración General del Estado" + Organismo destinatario "Ministerio de Sanidad" → "Ministerio de Sanidad"
- Registro "Principado de Asturias" + Hospital Jarrio → "Principado de Asturias"
- "Canal de Isabel II" → "Canal de Isabel II" (entidad con portal propio)
- "RTVE" → "RTVE" (entidad con portal propio)
- "Universidad de Oviedo" → "Universidad de Oviedo" (entidad con portal propio)

REGLAS PARA redirectedToPublicBody:
- Si el órgano es genérico (Consejería, Servicio, Dirección General, etc.), AÑADE el gobierno al que pertenece
- Usa el formato: "Nombre del órgano - Gobierno/Administración"
- Deduce el gobierno del contexto del documento (CCAA, ayuntamiento, ministerio, etc.)

Ejemplos:
- "Consejería de Agricultura" en documento de Castilla-La Mancha → "Consejería de Agricultura - Junta de Comunidades de Castilla-La Mancha"
- "Servicio de Salud" en documento de Castilla-La Mancha → "Servicio de Salud de Castilla-La Mancha (SESCAM)"
- "Consellería de Sanidade" en documento de Galicia → "Consellería de Sanidade - Xunta de Galicia"
- "Dirección General de Transparencia" en documento estatal → "Dirección General de Transparencia - Ministerio de la Presidencia"
- "Ayuntamiento de Toledo" → "Ayuntamiento de Toledo" (no necesita contexto adicional)

REGLAS PARA applicableLaw:
- SOLO incluye la ley de transparencia aplicable
- Para solicitudes estatales: "Ley 19/2013"
- Para Asturias: "Ley 8/2018 del Principado de Asturias"
- NO incluyas otras leyes mencionadas (contratos, procedimiento, etc.)

CÓDIGOS DE COMUNIDADES AUTÓNOMAS (autonomousCommunityCode):
- AND = Andalucía (Junta de Andalucía)
- ARA = Aragón (Gobierno de Aragón)
- AST = Principado de Asturias
- BAL = Illes Balears (Govern de les Illes Balears)
- CAN = Canarias (Gobierno de Canarias)
- CNT = Cantabria (Gobierno de Cantabria)
- CYL = Castilla y León (Junta de Castilla y León)
- CLM = Castilla-La Mancha (Junta de Comunidades de Castilla-La Mancha)
- CAT = Cataluña (Generalitat de Catalunya)
- CEU = Ceuta (Ciudad Autónoma de Ceuta)
- VAL = Comunitat Valenciana (Generalitat Valenciana)
- EXT = Extremadura (Junta de Extremadura)
- GAL = Galicia (Xunta de Galicia)
- MAD = Comunidad de Madrid
- MEL = Melilla (Ciudad Autónoma de Melilla)
- MUR = Región de Murcia
- NAV = Navarra (Gobierno de Navarra, Comunidad Foral)
- PVA = País Vasco (Gobierno Vasco, Eusko Jaurlaritza)
- RIO = La Rioja (Gobierno de La Rioja)
- null = Administración General del Estado (ministerios, organismos estatales)

REGLAS PARA autonomousCommunityCode:
- Identifica a qué comunidad autónoma pertenece la administración destinataria
- Para ministerios, organismos estatales (Adif, AENA, RTVE, etc.) → null
- Para ayuntamientos/diputaciones, usa el código de su CCAA
- Para universidades públicas, usa el código de la CCAA donde están ubicadas
- Para entidades autonómicas (Consejerías, SAS, SERGAS, etc.) → código de su CCAA

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
    "documentType": "tipo de documento (uno de: solicitud, acuse_recibo, inicio_tramitacion, resolucion, notificacion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_ctbg, otro)",
    "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
    "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
    "autonomousCommunityCode": "código de la CCAA a la que pertenece la administración (ver tabla abajo, null si es estatal)",
    "documentDate": "fecha del documento en formato YYYY-MM-DD",
    "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
    "summary": "resumen breve del contenido (máximo 200 caracteres)",
    "status": "estado que indica el documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
    "isExtension": "true si es una notificación de prórroga, false en caso contrario",
    "extensionDays": "número de días de prórroga (si aplica, null si no)",
    "newDeadlineDate": "nueva fecha límite si se menciona explícitamente en formato YYYY-MM-DD (null si no)",
    "denialReason": "motivo de denegación si el documento es una resolución denegatoria (null si no aplica)",
    "isRedirection": "true si el documento comunica que la solicitud se traslada/redirige a otro órgano porque la información no obra en poder del órgano original (art. 19.1 Ley 19/2013), false en caso contrario",
    "redirectedToPublicBody": "nombre COMPLETO del órgano al que se traslada, incluyendo el gobierno al que pertenece (ver reglas abajo)",
    "isThirdPartyRights": "true si el documento notifica que la solicitud afecta a derechos de terceros y se abre plazo de alegaciones (art. 19.3 Ley 19/2013), false en caso contrario",
    "thirdPartyAllegationsDeadline": "fecha límite para alegaciones de terceros en formato YYYY-MM-DD (si se menciona, null si no)",
    "isProcessingStart": "true si es un documento de comienzo/inicio de tramitación que notifica el inicio del plazo de 1 mes para resolver (art. 20.1 Ley 19/2013), false en caso contrario",
    "processingStartDate": "fecha a partir de la cual comienza el cómputo del plazo en formato YYYY-MM-DD (si isProcessingStart es true)",
    "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018', 'Gastos publicidad Ayuntamiento 2023'). NO uses 'Solicitud de acceso a información pública'.",
    "requestDescription": "descripción detallada de la información solicitada"
}

REGLAS PARA publicBodyName:
- Identifica la ENTIDAD que tramita la solicitud (la que tiene portal de transparencia propio)
- Busca pistas en: registro electrónico, cabecera oficial, sello, pie de página
- USA TU CONOCIMIENTO de la administración española para elegir el nivel correcto

NIVEL CORRECTO - ni demasiado genérico ni demasiado específico:
- "Administración General del Estado" es DEMASIADO GENÉRICO → busca el organismo destinatario real (Adif, AENA, Ministerio concreto, etc.)
- "Consejería de X" sin más contexto es DEMASIADO GENÉRICO → usa el nombre de la CCAA (Principado de Asturias, Junta de Andalucía, etc.)
- Entidades con personalidad jurídica propia (Adif, RTVE, Canal de Isabel II, AENA, universidades) → usar su nombre directamente

En justificantes de registro electrónico:
- Si "Oficina de registro" es "Administración General del Estado", mira "Organismo destinatario" para el órgano real
- Si "Oficina de registro" es de una CCAA (ej: "Principado de Asturias"), usa el nombre de la CCAA

Ejemplos:
- Registro "Administración General del Estado" + Organismo destinatario "Adif" → "Adif"
- Registro "Administración General del Estado" + Organismo destinatario "Ministerio de Sanidad" → "Ministerio de Sanidad"
- Registro "Principado de Asturias" + Hospital Jarrio → "Principado de Asturias"
- "Canal de Isabel II" → "Canal de Isabel II" (entidad con portal propio)
- "RTVE" → "RTVE" (entidad con portal propio)
- "Universidad de Oviedo" → "Universidad de Oviedo" (entidad con portal propio)
- "Ayuntamiento de Madrid" → "Ayuntamiento de Madrid"

REGLAS PARA redirectedToPublicBody:
- Si el órgano es genérico (Consejería, Servicio, Dirección General, etc.), AÑADE el gobierno al que pertenece
- Usa el formato: "Nombre del órgano - Gobierno/Administración"
- Deduce el gobierno del contexto del documento (CCAA, ayuntamiento, ministerio, etc.)

Ejemplos:
- "Consejería de Agricultura" en documento de Castilla-La Mancha → "Consejería de Agricultura - Junta de Comunidades de Castilla-La Mancha"
- "Servicio de Salud" en documento de Castilla-La Mancha → "Servicio de Salud de Castilla-La Mancha (SESCAM)"
- "Consellería de Sanidade" en documento de Galicia → "Consellería de Sanidade - Xunta de Galicia"
- "Dirección General de Transparencia" en documento estatal → "Dirección General de Transparencia - Ministerio de la Presidencia"
- "Ayuntamiento de Toledo" → "Ayuntamiento de Toledo" (no necesita contexto adicional)

REGLAS PARA applicableLaw:
- SOLO incluye la ley de transparencia aplicable
- Para solicitudes estatales: "Ley 19/2013"
- Para Asturias: "Ley 8/2018 del Principado de Asturias"
- NO incluyas otras leyes mencionadas (contratos, procedimiento, etc.)

CÓDIGOS DE COMUNIDADES AUTÓNOMAS (autonomousCommunityCode):
- AND = Andalucía (Junta de Andalucía)
- ARA = Aragón (Gobierno de Aragón)
- AST = Principado de Asturias
- BAL = Illes Balears (Govern de les Illes Balears)
- CAN = Canarias (Gobierno de Canarias)
- CNT = Cantabria (Gobierno de Cantabria)
- CYL = Castilla y León (Junta de Castilla y León)
- CLM = Castilla-La Mancha (Junta de Comunidades de Castilla-La Mancha)
- CAT = Cataluña (Generalitat de Catalunya)
- CEU = Ceuta (Ciudad Autónoma de Ceuta)
- VAL = Comunitat Valenciana (Generalitat Valenciana)
- EXT = Extremadura (Junta de Extremadura)
- GAL = Galicia (Xunta de Galicia)
- MAD = Comunidad de Madrid
- MEL = Melilla (Ciudad Autónoma de Melilla)
- MUR = Región de Murcia
- NAV = Navarra (Gobierno de Navarra, Comunidad Foral)
- PVA = País Vasco (Gobierno Vasco, Eusko Jaurlaritza)
- RIO = La Rioja (Gobierno de La Rioja)
- null = Administración General del Estado (ministerios, organismos estatales)

REGLAS PARA autonomousCommunityCode:
- Identifica a qué comunidad autónoma pertenece la administración destinataria
- Para ministerios, organismos estatales (Adif, AENA, RTVE, etc.) → null
- Para ayuntamientos/diputaciones, usa el código de su CCAA
- Para universidades públicas, usa el código de la CCAA donde están ubicadas
- Para entidades autonómicas (Consejerías, SAS, SERGAS, etc.) → código de su CCAA

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

        // Map document type to enum
        $data['documentType'] = DocumentType::fromAiValue($data['documentType'] ?? 'otro');

        // If isRedirection is true but documentType wasn't detected correctly, override it
        if (($data['isRedirection'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Redirection;
        }

        // If isThirdPartyRights is true but documentType wasn't detected correctly, override it
        if (($data['isThirdPartyRights'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ThirdPartyRights;
        }

        // If isProcessingStart is true but documentType wasn't detected correctly, override it
        if (($data['isProcessingStart'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ProcessingStart;
        }

        return $data;
    }
}
