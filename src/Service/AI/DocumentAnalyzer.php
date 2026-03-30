<?php

namespace App\Service\AI;

use App\Entity\Document;
use App\Enum\DocumentType;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DocumentAnalyzer
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $geminiModel,
    ) {
    }

    /**
     * Analyze a document using Gemini AI and extract relevant information
     * @return array<string, mixed>
     */
    private const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4MB

    public function analyze(Document $document): array
    {
        if ($document->getFileSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Documento demasiado grande para análisis automático (%s). Máximo: 4MB.',
                $document->getFileSizeFormatted()
            ));
        }

        return $this->analyzeSingle($document);
    }

    private function analyzeSingle(Document $document): array
    {
        $content = $this->documentsStorage->read($document->getStoredFilename());
        $mimeType = $document->getMimeType();

        $parts = [];

        if ($mimeType === 'text/plain') {
            $label = $document->isFromEmail() ? 'Cuerpo de email' : 'Documento de texto';
            $parts[] = [
                'text' => sprintf("[%s: %s]\n%s", $label, $document->getOriginalFilename(), $content),
            ];
        } else {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => base64_encode($content),
                ],
            ];

            $contextLabel = sprintf('[Documento: %s]', $document->getOriginalFilename());

            if ($document->isFromPortal()) {
                $portalContext = $this->buildPortalContext($document);
                if ($portalContext) {
                    $contextLabel .= "\n" . $portalContext;
                }
            }

            $parts[] = ['text' => $contextLabel];
        }

        $parts[] = ['text' => $this->buildPrompt()];

        $response = $this->callGeminiApiWithParts($parts);

        return $this->parseResponse($response);
    }

    /**
     * Analyze multiple related documents together for better context.
     * Returns an array with per-document analysis results plus shared fields.
     *
     * @param Document[] $documents
     * @return array{shared: array<string, mixed>, documents: array<int, array<string, mixed>>}
     */
    public function analyzeMultiple(array $documents): array
    {
        if (empty($documents)) {
            throw new \InvalidArgumentException('At least one document is required');
        }

        // Single document — run single-document analysis directly
        if (count($documents) === 1) {
            $result = $this->analyzeSingle($documents[0]);
            return [
                'shared' => $result,
                'documents' => [$result],
            ];
        }

        // Filter out documents that are too large
        $documents = array_values(array_filter(
            $documents,
            fn(Document $d) => $d->getFileSize() <= self::MAX_FILE_SIZE,
        ));

        if (empty($documents)) {
            throw new \RuntimeException('Todos los documentos superan el tamaño máximo para análisis (4MB).');
        }

        $parts = [];

        // Add each document as a separate part
        foreach ($documents as $index => $document) {
            $content = $this->documentsStorage->read($document->getStoredFilename());
            $mimeType = $document->getMimeType();

            if ($mimeType === 'text/plain') {
                $label = $document->isFromEmail() ? 'Cuerpo de email' : 'Documento de texto';
                $parts[] = [
                    'text' => sprintf("[Documento %d - %s: %s]\n%s", $index + 1, $label, $document->getOriginalFilename(), $content),
                ];
            } else {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($content),
                    ],
                ];
                $contextLabel = sprintf('[Documento %d: %s]', $index + 1, $document->getOriginalFilename());

                // Add portal metadata context if available
                if ($document->isFromPortal()) {
                    $portalContext = $this->buildPortalContext($document);
                    if ($portalContext) {
                        $contextLabel .= "\n" . $portalContext;
                    }
                }

                $parts[] = [
                    'text' => $contextLabel,
                ];
            }
        }

        $parts[] = ['text' => $this->buildMultiDocumentPrompt(count($documents))];

        $response = $this->callGeminiApiWithParts($parts);

        return $this->parseMultiResponse($response, count($documents));
    }

    private function buildMultiDocumentPrompt(int $documentCount): string
    {
        return <<<PROMPT
Tienes {$documentCount} documentos relacionados con la MISMA solicitud de acceso a información pública en España.
Pueden incluir: la solicitud original, el justificante de registro, acuses de recibo, resoluciones, traslados, inicios de tramitación, etc.

Analiza TODOS los documentos juntos para extraer la información más completa y precisa.
Si un dato aparece en un documento pero no en otro, usa el que tengas.
El justificante de registro suele tener el número de registro y la administración correcta.

IMPORTANTE: Devuelve un JSON con DOS secciones:
1. "shared" — información compartida de la solicitud (extraída de TODOS los documentos juntos)
2. "documents" — un array con {$documentCount} elementos, uno POR CADA documento, en el mismo orden en que se presentaron (Documento 1, Documento 2, etc.)

Cada documento tiene su PROPIO tipo, fecha y resumen. NO clasifiques todos los documentos con el mismo tipo.

{
    "shared": {
        "referenceNumber": "número de expediente o registro (busca 'Nº de registro', 'Expediente', etc.)",
        "publicBodyName": "ADMINISTRACIÓN COMPETENTE (ver reglas abajo)",
        "autonomousCommunityCode": "código de la CCAA a la que pertenece la administración (ver tabla abajo, null si es estatal)",
        "applicableLaw": "SOLO la ley de transparencia principal (Ley 19/2013 o la autonómica equivalente)",
        "requestTitle": "RESUMEN CORTO de qué información se solicita (ej: 'Contratos menores Hospital Jarrio 2018'). NO uses 'Solicitud de acceso a información pública'.",
        "requestDescription": "descripción detallada de la información solicitada, formateada en Markdown. Usa listas, negritas y estructura clara para facilitar la lectura."
    },
    "documents": [
        {
            "documentType": "tipo de ESTE documento concreto (uno de: solicitud, acuse_recibo, inicio_tramitacion, resolucion, notificacion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, otro)",
            "documentDate": "fecha de ESTE documento en formato YYYY-MM-DD",
            "summary": "resumen breve de ESTE documento (máximo 200 caracteres)",
            "status": "estado que indica ESTE documento (uno de: enviada, en_tramite, concedida, denegada, silencio, pendiente, null si no aplica)",
            "isExtension": false,
            "extensionDays": null,
            "newDeadlineDate": null,
            "denialReason": null,
            "isRedirection": false,
            "redirectedToPublicBody": null,
            "isThirdPartyRights": false,
            "thirdPartyAllegationsDeadline": null,
            "isProcessingStart": false,
            "processingStartDate": null,
            "alegationPoints": null
        }
    ]
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

REGLAS PARA documentType (valores posibles: solicitud, acuse_recibo, inicio_tramitacion, resolucion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, otro):

Usa "resolucion_reclamacion" si el documento es una resolución emitida por un organismo de transparencia (CTBG, GAIP, Comisionado de Transparencia, Consejo de Transparencia autonómico, etc.) que resuelve una reclamación interpuesta por el ciudadano. No confundir con "resolucion" que es la respuesta directa de la Administración a la solicitud.

Usa "alegaciones" si el documento es un escrito de alegaciones de la Administración durante un proceso de reclamación ante un organismo de transparencia. Es la defensa/respuesta de la Administración ante la reclamación del ciudadano.

IMPORTANTE - Usa "resolucion" si el documento:
- ESTIMA (concede/otorga) el acceso a la información solicitada
- DESESTIMA (deniega/rechaza) el acceso a la información
- ESTIMA PARCIALMENTE el acceso
- INADMITE la solicitud
- Contiene una sección "RESOLUCIÓN" con un fallo/decisión final sobre el acceso
- Aunque el título sea "NOTIFICACIÓN", si contiene una resolución estimatoria o desestimatoria, es "resolucion"

Palabras clave que indican "resolucion":
- "se estima", "estimar la solicitud", "estimación"
- "se desestima", "desestimar", "desestimación"
- "se concede acceso", "se deniega acceso"
- "RESUELVO", "RESOLUCIÓN" (como sección de decisión)

NO uses "resolucion" para:
- Meros acuses de recibo → usa "acuse_recibo"
- Notificaciones de inicio de tramitación → usa "inicio_tramitacion"
- Prórrogas del plazo → usa "prorroga"
- Traslados a otro órgano → usa "traslado"

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
    "documentType": "tipo de documento (ver REGLAS PARA documentType abajo, incluyendo 'alegaciones')",
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
    "requestDescription": "descripción detallada de la información solicitada, formateada en Markdown. Usa listas, negritas y estructura clara para facilitar la lectura.",
    "alegationPoints": "si el documento es un escrito de alegaciones de la Administración (durante proceso de reclamación ante CTBG u organismo equivalente), array con los principales argumentos/puntos de defensa de la Administración. null si no es un escrito de alegaciones"
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

REGLAS PARA documentType (valores posibles: solicitud, acuse_recibo, inicio_tramitacion, resolucion, prorroga, traslado, afectacion_terceros, reclamacion, acuse_recibo_reclamacion, inicio_tramitacion_reclamacion, resolucion_reclamacion, alegaciones, otro):

Usa "resolucion_reclamacion" si el documento es una resolución emitida por un organismo de transparencia (CTBG, GAIP, Comisionado de Transparencia, Consejo de Transparencia autonómico, etc.) que resuelve una reclamación interpuesta por el ciudadano. No confundir con "resolucion" que es la respuesta directa de la Administración a la solicitud.

Usa "alegaciones" si el documento es un escrito de alegaciones de la Administración durante un proceso de reclamación ante un organismo de transparencia. Es la defensa/respuesta de la Administración ante la reclamación del ciudadano.

IMPORTANTE - Usa "resolucion" si el documento:
- ESTIMA (concede/otorga) el acceso a la información solicitada
- DESESTIMA (deniega/rechaza) el acceso a la información
- ESTIMA PARCIALMENTE el acceso
- INADMITE la solicitud
- Contiene una sección "RESOLUCIÓN" con un fallo/decisión final sobre el acceso
- Aunque el título sea "NOTIFICACIÓN", si contiene una resolución estimatoria o desestimatoria, es "resolucion"

Palabras clave que indican "resolucion":
- "se estima", "estimar la solicitud", "estimación"
- "se desestima", "desestimar", "desestimación"
- "se concede acceso", "se deniega acceso"
- "RESUELVO", "RESOLUCIÓN" (como sección de decisión)

NO uses "resolucion" para:
- Meros acuses de recibo → usa "acuse_recibo"
- Notificaciones de inicio de tramitación → usa "inicio_tramitacion"
- Prórrogas del plazo → usa "prorroga"
- Traslados a otro órgano → usa "traslado"

IMPORTANTE:
- Responde SOLO con el JSON, sin texto adicional
- Si no puedes determinar un campo, usa null
- Para documentType usa exactamente uno de los valores indicados
- Las fechas deben estar en formato YYYY-MM-DD
PROMPT;
    }

    /**
     * Map portal notification types to the documentType values used by the AI prompt.
     * This ensures documents from the transparency portal are classified correctly
     * regardless of what the AI extracts from the PDF content.
     */
    private const PORTAL_TYPE_MAP = [
        // Expediente lifecycle
        'Resolución' => 'resolucion',
        'Aceptación Competencias' => 'inicio_tramitacion',
        'Requerimiento' => 'otro',
        'Alegación' => 'alegaciones',
        'Información Pública' => 'otro',
        'Audiencia' => 'audiencia',
        'Prueba' => 'otro',
        'Informe' => 'otro',
        'Respuesta de informe' => 'otro',
        'Pasar a trámite' => 'inicio_tramitacion',
        'Modificación de plazo' => 'prorroga',
        'Suspensión' => 'afectacion_terceros',
        'Cancelación de suspensión' => 'otro',
        'Silencio administrativo' => 'otro',
        'Caso de excepción' => 'otro',
        'Anexo' => 'otro',
        'Requerimiento de pago' => 'otro',
        'Traslado de competencias' => 'traslado',
        'Traslado de expediente' => 'traslado',
        'Cambiar modo de tramitación' => 'otro',
        'Aportar documentación' => 'otro',
        'Finalizar Expediente' => 'resolucion',
        'Duplicar Expediente' => 'otro',
    ];

    /**
     * Map portal notification concept prefixes to documentType values.
     */
    private const PORTAL_CONCEPT_MAP = [
        'COMUNICACIÓN_CAMBIO_ÁMBITO' => 'traslado',
        'ACEPTACION_COMPETENCIAS' => 'inicio_tramitacion',
        'RESOLUCION' => 'resolucion',
    ];

    /**
     * Build context string from portal source metadata to help AI classify correctly.
     */
    private function buildPortalContext(Document $document): ?string
    {
        $meta = $document->getSourceMetadata();
        if (!$meta) {
            return null;
        }

        $lines = ['[CONTEXTO DEL PORTAL DE TRANSPARENCIA — usa esta información para clasificar el documento]'];

        // Determine the expected documentType from portal metadata
        $portalHint = $this->resolvePortalDocumentType($meta);
        if ($portalHint) {
            $lines[] = sprintf('IMPORTANTE: Este documento DEBE clasificarse como documentType="%s" según la tipología del portal.', $portalHint);
        }

        if (!empty($meta['notificationType'])) {
            $lines[] = sprintf('Tipo de notificación en el portal: %s', $meta['notificationType']);
        }
        if (!empty($meta['notificationConcept'])) {
            $lines[] = sprintf('Concepto: %s', $meta['notificationConcept']);
        }
        if (!empty($meta['notificationState'])) {
            $lines[] = sprintf('Estado en el portal: %s', $meta['notificationState']);
        }
        if (!empty($meta['expedienteRef'])) {
            $lines[] = sprintf('Expediente portal: %s', $meta['expedienteRef']);
        }
        if (!empty($meta['expedienteEstado'])) {
            $lines[] = sprintf('Estado expediente: %s', $meta['expedienteEstado']);
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }

    /**
     * Resolve the expected documentType from portal metadata.
     */
    private function resolvePortalDocumentType(array $meta): ?string
    {
        // First try concept prefix (more specific)
        $concept = $meta['notificationConcept'] ?? '';
        foreach (self::PORTAL_CONCEPT_MAP as $prefix => $type) {
            if (str_starts_with($concept, $prefix)) {
                return $type;
            }
        }

        // Then try notification type
        $notifType = $meta['notificationType'] ?? '';
        if (isset(self::PORTAL_TYPE_MAP[$notifType])) {
            return self::PORTAL_TYPE_MAP[$notifType];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function callGeminiApiWithParts(array $parts): array
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
                'maxOutputTokens' => 16384,
                'responseMimeType' => 'application/json',
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

        // If alegationPoints is a non-empty array and type is Other, set to Alegaciones
        if (!empty($data['alegationPoints']) && is_array($data['alegationPoints']) && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Alegaciones;
        }

        return $data;
    }

    /**
     * Parse a multi-document response that contains shared + per-document analyses.
     *
     * @return array{shared: array<string, mixed>, documents: array<int, array<string, mixed>>}
     */
    private function parseMultiResponse(array $response, int $expectedCount): array
    {
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse Gemini multi-response as JSON: ' . $text);
        }

        $shared = $data['shared'] ?? $data;
        $docResults = $data['documents'] ?? [];

        // If the AI didn't return the expected structure, fall back to single analysis
        if (empty($docResults) || !is_array($docResults)) {
            $single = $this->normalizeDocumentAnalysis($shared);
            return [
                'shared' => $single,
                'documents' => array_fill(0, $expectedCount, $single),
            ];
        }

        // Normalize each document analysis and merge with shared fields
        $documents = [];
        foreach ($docResults as $i => $docData) {
            $merged = array_merge($shared, $docData);
            $documents[] = $this->normalizeDocumentAnalysis($merged);
        }

        // If AI returned fewer documents than expected, pad with the shared analysis
        while (count($documents) < $expectedCount) {
            $documents[] = $this->normalizeDocumentAnalysis($shared);
        }

        return [
            'shared' => $this->normalizeDocumentAnalysis($shared),
            'documents' => $documents,
        ];
    }

    /**
     * Apply documentType enum mapping and flag-based overrides to a single document analysis.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDocumentAnalysis(array $data): array
    {
        $data['documentType'] = DocumentType::fromAiValue($data['documentType'] ?? 'otro');

        if (($data['isRedirection'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Redirection;
        }
        if (($data['isThirdPartyRights'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ThirdPartyRights;
        }
        if (($data['isProcessingStart'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ProcessingStart;
        }
        if (!empty($data['alegationPoints']) && is_array($data['alegationPoints']) && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Alegaciones;
        }

        return $data;
    }
}
