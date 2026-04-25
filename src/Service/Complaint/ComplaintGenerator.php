<?php

namespace App\Service\Complaint;

use App\DTO\ChatMessage;
use App\DTO\CitedResolution;
use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\ApplicableLaw;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\ResolutionRetriever;
use App\Service\TransparencyCouncilResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;

final class ComplaintGenerator
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly SuccessAnalyzer $successAnalyzer,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly TransparencyCouncilResolver $councilResolver,
    ) {
    }

    /**
     * @param ChatMessage[] $conversationHistory
     */
    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function generate(AccessRequest $accessRequest, array $conversationHistory = [], ?string $userDirections = null, array $documentContents = []): ComplaintDraft
    {
        if (!$this->canGenerateComplaint($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate complaint for this request. Status must be denied or delayed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyze($accessRequest);

        $transparencyCouncil = $this->getTransparencyCouncil($accessRequest->getApplicableLaw());
        $applicableLawName = $accessRequest->getApplicableLaw()->getName();

        $contextQuery = $this->buildContextQuery($accessRequest);
        $criteria = $this->criteriaRetriever->retrieve($contextQuery, 5);
        $resolutions = $this->resolutionRetriever->retrieveSimilarCases($contextQuery, 3);

        $hasResponseDocument = $this->hasResponseDocument($accessRequest);

        $prompt = $this->buildPrompt(
            $accessRequest,
            $transparencyCouncil,
            $applicableLawName,
            $criteria,
            $resolutions,
            $documentContents,
            $hasResponseDocument
        );

        if ($userDirections) {
            $prompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $content = $this->llmClient->chat(new ChatRequest(
            systemPrompt: $prompt,
            messages: $conversationHistory,
            size: ModelSize::Big,
            temperature: 0.3,
            maxOutputTokens: 8192,
        ));

        $content = $this->sanitizeHtmlResponse($content);

        $citedResolutions = $this->extractCitedResolutions($content, $resolutions);
        $citedCriteria = $this->extractCitedCriteria($content, $criteria);

        return new ComplaintDraft(
            content: $content,
            transparencyCouncil: $transparencyCouncil,
            applicableLaw: $applicableLawName,
            citedResolutions: $citedResolutions,
            citedCriteria: $citedCriteria,
            successAnalysis: $successAnalysis,
        );
    }

    public function saveComplaint(AccessRequest $accessRequest, ComplaintDraft $draft): Document
    {
        $filename = sprintf(
            'reclamacion_%s_%s.html',
            $accessRequest->getId()->toRfc4122(),
            (new \DateTime())->format('Y-m-d_H-i-s')
        );

        $this->documentsStorage->write($filename, $draft->content);

        $document = new Document();
        $document->setOriginalFilename('Reclamación.html');
        $document->setStoredFilename($filename);
        $document->setMimeType('text/html');
        $document->setFileSize(strlen($draft->content));
        $document->setType(DocumentType::Complaint);
        $document->setAccessRequest($accessRequest);
        $document->setUploadedBy($accessRequest->getUser());
        $document->setProcessed(true);
        $document->setAiMetadata([
            'transparencyCouncil' => $draft->transparencyCouncil,
            'applicableLaw' => $draft->applicableLaw,
            'citedResolutions' => array_map(fn($r) => $r->toArray(), $draft->citedResolutions),
            'citedCriteria' => $draft->citedCriteria,
            'successAnalysis' => $draft->successAnalysis?->toArray(),
            'generatedAt' => (new \DateTime())->format('c'),
        ]);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    public function canGenerateComplaint(AccessRequest $accessRequest): bool
    {
        if (in_array($accessRequest->getStatus(), [AccessRequest::STATUS_DENIED, AccessRequest::STATUS_DELAYED], true)) {
            return true;
        }

        if ($accessRequest->isDeadlinePassed() && !in_array($accessRequest->getStatus(), [AccessRequest::STATUS_GRANTED, AccessRequest::STATUS_GRANTED_COMPLETED], true)) {
            return true;
        }

        return false;
    }

    private function getTransparencyCouncil(ApplicableLaw $law): string
    {
        return $this->councilResolver->forLaw($law);
    }

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function formatDocumentContents(array $documentContents): string
    {
        if (empty($documentContents)) {
            return '';
        }

        $text = "## DOCUMENTOS ADJUNTOS DEL EXPEDIENTE\n\n";
        foreach ($documentContents as $i => $doc) {
            $text .= sprintf("### Documento %d: %s (%s)\n\n%s\n\n---\n\n", $i + 1, $doc['name'], $doc['type'], $doc['content']);
        }

        return $text;
    }

    private function buildContextQuery(AccessRequest $accessRequest): string
    {
        $parts = [
            $accessRequest->getTitle(),
            $accessRequest->getDescription(),
        ];

        if ($accessRequest->getResolutionNotes()) {
            $parts[] = 'Motivo de denegación: ' . $accessRequest->getResolutionNotes();
        }

        if ($accessRequest->getStatus() === AccessRequest::STATUS_DELAYED) {
            $parts[] = 'Silencio administrativo negativo';
        }

        return implode('. ', $parts);
    }

    private function hasResponseDocument(AccessRequest $accessRequest): bool
    {
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Response) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function buildPrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        array $criteria,
        array $resolutions,
        array $documentContents = [],
        bool $hasResponseDocument = false
    ): string {
        $status = match (true) {
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'no contestada (silencio administrativo negativo)',
            $accessRequest->isDeadlinePassed() => 'no contestada (silencio administrativo negativo - plazo vencido)',
            default => 'pendiente de resolución',
        };

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No se ha indicado motivo de denegación';

        $silencePositive = $accessRequest->getApplicableLaw()->isSilenceIsPositive();

        if ($hasResponseDocument) {
            $silenceBlock = '';
        } elseif ($silencePositive) {
            $silenceBlock = <<<SILENCE


## SUPUESTO DE SILENCIO ADMINISTRATIVO POSITIVO

NO se ha aportado ningún documento de respuesta de la Administración. Según la ley aplicable ({$applicableLawName}), el silencio administrativo en materia de acceso a información pública tiene sentido POSITIVO: transcurrido el plazo legal sin respuesta, la solicitud se entiende ESTIMADA por silencio y el ciudadano adquiere el derecho de acceso a la información solicitada.

La reclamación NO debe argumentarse como si se tratara de una denegación tácita, sino como la falta de MATERIALIZACIÓN de un derecho ya reconocido por silencio positivo.

Sé BREVE Y DIRECTO. No desarrolles argumentación extensa sobre el fondo: el derecho ya ha quedado reconocido por silencio. Céntrate en:
- La constatación del silencio positivo y su efecto estimatorio conforme a la ley autonómica aplicable.
- La OBLIGACIÓN DE RESOLVER de la Administración (art. 21 Ley 39/2015), cuyo incumplimiento no puede perjudicar al ciudadano.
- Por qué lo solicitado NO ENCAJA en ninguna de las CAUSAS DE INADMISIÓN del art. 18 Ley 19/2013 (o equivalente autonómico): no es información auxiliar, no requiere reelaboración, el órgano es competente, no es repetitiva ni abusiva, no está en curso de elaboración.
- Por qué lo solicitado NO ENTRA en los LÍMITES al derecho de acceso del art. 14 Ley 19/2013 (o equivalente autonómico), dado que la Administración no ha alegado ninguno.

En la SOLICITUD, pide al {$transparencyCouncil} que DECLARE el derecho de acceso ya adquirido por silencio positivo y ordene a la Administración la ENTREGA EFECTIVA de la información.

NO inventes motivos de denegación: parte explícitamente de que la Administración no ha ofrecido ninguno.
SILENCE;
        } else {
            $silenceBlock = <<<'SILENCE'


## SUPUESTO DE SILENCIO ADMINISTRATIVO NEGATIVO

NO se ha aportado ningún documento de respuesta de la Administración al expediente. Debes redactar la reclamación asumiendo que NO ha habido resolución expresa y que, transcurrido el plazo legal de un mes, se ha producido SILENCIO ADMINISTRATIVO con sentido DESESTIMATORIO conforme al artículo 20.4 de la Ley 19/2013 (o precepto equivalente de la ley autonómica aplicable).

Sé BREVE Y DIRECTO. No hace falta desarrollar una argumentación jurídica extensa sobre el fondo del asunto: céntrate en (a) la obligación de resolver y (b) que lo solicitado no encaja en límites ni causas de inadmisión.

En los Fundamentos Jurídicos debes:
- Invocar la OBLIGACIÓN DE RESOLVER de la Administración (art. 21 Ley 39/2015): toda Administración está obligada a dictar resolución expresa y notificarla en todos los procedimientos, incluidos los de acceso a información pública.
- Recordar el plazo legal de un mes y el sentido desestimatorio del silencio (art. 20 Ley 19/2013 o precepto autonómico equivalente).
- Destacar por qué lo solicitado NO ENCAJA en ninguna de las CAUSAS DE INADMISIÓN del art. 18 Ley 19/2013 (o equivalente autonómico): no es información auxiliar, no requiere reelaboración, el órgano es competente, no es repetitiva ni abusiva, no está en curso de elaboración.
- Destacar por qué lo solicitado NO ENTRA en los LÍMITES al derecho de acceso del art. 14 Ley 19/2013 (o equivalente autonómico), dado que la Administración no ha alegado ninguno.
- Señalar que el silencio NO EXIME a la Administración de su deber de resolver expresamente ni constituye una denegación válidamente motivada, por lo que la falta de motivación vicia la denegación presunta y, por sí sola, justifica la estimación de la reclamación.

NO inventes motivos de denegación: parte explícitamente de que la Administración no ha ofrecido ninguno.
SILENCE;
        }

        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $resolutionsText = $this->resolutionRetriever->formatForPrompt($resolutions);

        $timeline = "El día {$accessRequest->getSentAt()->format('d/m/Y')} presenté solicitud de acceso a información pública";
        if ($accessRequest->getAcknowledgedAt()) {
            $timeline .= ", recibiendo acuse de recibo el {$accessRequest->getAcknowledgedAt()->format('d/m/Y')}";
        }
        if ($accessRequest->getExternalId()) {
            $timeline .= " con número de registro {$accessRequest->getExternalId()}";
        }
        $timeline .= ".";

        if ($accessRequest->getResolvedAt()) {
            $timeline .= " La Administración resolvió el {$accessRequest->getResolvedAt()->format('d/m/Y')}.";
        } else {
            $timeline .= " Transcurrido el plazo legal de un mes sin obtener respuesta, se ha producido silencio administrativo negativo.";
        }

        $prompt = <<<PROMPT
Eres un abogado especialista en derecho de acceso a información pública en España.
Redacta una reclamación ante el {$transparencyCouncil} con la siguiente estructura:

## 1. RESUMEN DE LA RECLAMACIÓN

Escribe un párrafo breve que resuma:
- Qué información se solicitó: {$accessRequest->getTitle()}
- A qué organismo: {$accessRequest->getPublicBody()->getName()}
- Qué ocurrió: {$status}
- Motivo alegado por la Administración: {$denialReason}
- Por qué debe estimarse la reclamación (una frase)

## 2. ANTECEDENTES

Redacta los antecedentes en PROSA NARRATIVA (párrafos, no listas con viñetas). Incluye esta información de forma fluida:
{$timeline}

## 3. FUNDAMENTOS JURÍDICOS

Desarrolla los fundamentos jurídicos basándote en:
- {$applicableLawName}
- Los criterios interpretativos recuperados (ver abajo) — solo si son REALMENTE relevantes
- Las resoluciones favorables similares (ver abajo) — solo si son REALMENTE relevantes

### CÓMO JUZGAR LA RELEVANCIA DE RESOLUCIONES Y CRITERIOS

Las resoluciones y criterios que verás abajo te llegan por búsqueda semántica — es decir, son solo CANDIDATOS. El sistema NO garantiza que sean aplicables al caso. Muchos no lo serán. Tu trabajo es leerlos y descartar los que no encajen.

Protocolo obligatorio antes de citar cualquier resolución:
1. Lee primero el **resumen** y los **puntos clave** de cada resolución. Sirven como primer filtro de relevancia.
2. Si, a la vista del resumen y los puntos clave, la resolución aborda una cuestión jurídica realmente aplicable al caso actual, consulta su **extracto del texto completo** para verificar que el razonamiento es transferible.
3. Solo si, después de leer esos tres elementos, estás seguro de que la resolución es genuinamente aplicable, cítala.
4. Si tienes la más mínima duda sobre si una resolución aplica al caso, NO la cites. Es preferible una reclamación más breve y segura que una extensa con citas improcedentes.

Para los criterios interpretativos, aplica la misma prudencia: el epígrafe o título del criterio ya NO aparece en las cabeceras porque a veces era impreciso. Juzga la aplicabilidad leyendo el TEXTO del criterio, no por su identificador.

### CÓMO CITAR

Cuando cites una resolución o un criterio, IDENTIFICA SIEMPRE al órgano que lo emitió (consejo de transparencia, tribunal, etc.) y resume en tus propias palabras qué establece — no te limites a dar el número.

Ejemplos de cita correcta:
- "como estableció el {$transparencyCouncil} en su Resolución R/0123/2023, al conocer de un caso análogo en el que…"
- "el Tribunal Supremo, en su sentencia de 16 de octubre de 2017 (rec. 75/2017), confirmó que…"
- "el Criterio Interpretativo CI/004/2015, del Consejo de Transparencia y Buen Gobierno, establece que…"

Cuando cites un criterio interpretativo, usa SIEMPRE la fórmula literal «Criterio <identificador>» (por ejemplo «Criterio CI/004/2015»). Es el único formato que el sistema reconocerá como cita.

Si el órgano emisor de una fuente no consta en el contexto proporcionado, no inventes el nombre: omite la cita.

## 4. SOLICITUD

Redacta la petición formal al {$transparencyCouncil} solicitando que estime la reclamación.

---

## CONTEXTO DE LA SOLICITUD

**Título de la solicitud:** {$accessRequest->getTitle()}

**Descripción completa:**
{$accessRequest->getDescription()}

**Organismo:** {$accessRequest->getPublicBody()->getName()}

**Número de registro:** {$accessRequest->getExternalId()}

---

{$this->formatDocumentContents($documentContents)}
## CRITERIOS INTERPRETATIVOS RECUPERADOS

{$criteriaText}

---

## RESOLUCIONES FAVORABLES SIMILARES

{$resolutionsText}

---
{$silenceBlock}
## REGLAS DE REDACCIÓN

1. DOCUMENTO COMPLETO: El texto debe estar listo para firmar, sin huecos por rellenar
2. SIN PLACEHOLDERS: NUNCA escribas [nombre], [fecha], [espacio para...], [completar], [firma], etc.
3. ANTECEDENTES EN PROSA: Los antecedentes deben redactarse en párrafos narrativos, NO en listas con viñetas
4. ESPAÑOL JURÍDICO: Usa lenguaje formal jurídico-administrativo
5. CITAS RELEVANTES Y ATRIBUIDAS: Solo menciona una resolución, criterio interpretativo o doctrina si es REALMENTE relevante para el fondo de la reclamación — no las incluyas como adorno ni para engrosar el texto. Cuando cites una resolución o doctrina, IDENTIFICA SIEMPRE al órgano que la emitió (ej. "el {$transparencyCouncil}, en su Resolución R/0123/2023..."; "el Tribunal Supremo, en su sentencia de 16 de octubre de 2017..."). Si el órgano emisor no consta en las fuentes proporcionadas, no inventes el nombre.
6. NO incluir encabezado con datos del reclamante (el usuario los añadirá después)
7. FORMATO HTML: Devuelve HTML semántico usando ÚNICAMENTE estas etiquetas: <h1>, <p>, <strong>, <em>, <ol>, <ul>, <li>, <blockquote>, <br>, <a>. NO uses <h2>, <h3>, <div>, <span>, <html>, <head>, <body>, estilos inline ni clases CSS. Usa <h1> para cada sección principal ("Resumen de la reclamación", "Antecedentes", "Fundamentos jurídicos", "Solicitud"). Para subsecciones dentro de una sección, usa un párrafo con <strong> al inicio en lugar de un encabezado adicional.
8. SUCINTO EN LO FORMAL: Sé breve y directo en cuestiones formales y de procedimiento. Reserva el detalle y la extensión para la argumentación jurídica de fondo, y aún así — especialmente en supuestos de silencio administrativo — prefiere la brevedad: no alargues la argumentación cuando el caso es sencillo.
9. SOLO FUENTES PROPORCIONADAS: Basa tu argumentación EXCLUSIVAMENTE en los criterios interpretativos y resoluciones proporcionados arriba. NO inventes, cites ni menciones ninguna resolución, sentencia, criterio interpretativo o referencia normativa que no aparezca explícitamente en el contexto proporcionado. Si no hay suficientes fuentes, argumenta con los principios generales de la ley aplicable sin fabricar referencias concretas.

Responde ÚNICAMENTE con el HTML de la reclamación, sin explicaciones adicionales, sin comentarios y sin envolver la respuesta en un bloque de código markdown.
PROMPT;

        return $prompt;
    }

    public function canGenerateAlegationResponse(AccessRequest $accessRequest): bool
    {
        return $accessRequest->getComplaint()?->getStatus() === AccessRequestComplaint::STATUS_RECLAIMED;
    }

    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function generateAlegationResponse(AccessRequest $accessRequest, array $conversationHistory = [], ?string $userDirections = null, array $documentContents = []): ComplaintDraft
    {
        if (!$this->canGenerateAlegationResponse($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate alegation response. Complaint status must be reclaimed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyze($accessRequest);

        $transparencyCouncil = $this->getTransparencyCouncil($accessRequest->getApplicableLaw());
        $applicableLawName = $accessRequest->getApplicableLaw()->getName();

        $contextQuery = $this->buildContextQuery($accessRequest);
        $criteria = $this->criteriaRetriever->retrieve($contextQuery, 5);
        $resolutions = $this->resolutionRetriever->retrieveSimilarCases($contextQuery, 3);

        $alegacionesContent = $this->getAlegacionesContent($accessRequest);
        $alegationPoints = $this->getAlegationPoints($accessRequest);

        $prompt = $this->buildAlegationResponsePrompt(
            $accessRequest,
            $transparencyCouncil,
            $applicableLawName,
            $criteria,
            $resolutions,
            $alegacionesContent,
            $alegationPoints,
            $documentContents
        );

        if ($userDirections) {
            $prompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $content = $this->llmClient->chat(new ChatRequest(
            systemPrompt: $prompt,
            messages: $conversationHistory,
            size: ModelSize::Big,
            temperature: 0.3,
            maxOutputTokens: 8192,
        ));

        $content = $this->sanitizeHtmlResponse($content);

        $citedResolutions = $this->extractCitedResolutions($content, $resolutions);
        $citedCriteria = $this->extractCitedCriteria($content, $criteria);

        return new ComplaintDraft(
            content: $content,
            transparencyCouncil: $transparencyCouncil,
            applicableLaw: $applicableLawName,
            citedResolutions: $citedResolutions,
            citedCriteria: $citedCriteria,
            successAnalysis: $successAnalysis,
        );
    }

    public function saveAlegationResponse(AccessRequest $accessRequest, ComplaintDraft $draft): Document
    {
        $filename = sprintf(
            'respuesta_alegaciones_%s_%s.txt',
            $accessRequest->getId()->toRfc4122(),
            (new \DateTime())->format('Y-m-d_H-i-s')
        );

        $this->documentsStorage->write($filename, $draft->content);

        $document = new Document();
        $document->setOriginalFilename('Respuesta a alegaciones.txt');
        $document->setStoredFilename($filename);
        $document->setMimeType('text/plain');
        $document->setFileSize(strlen($draft->content));
        $document->setType(DocumentType::AlegationResponse);
        $document->setAccessRequest($accessRequest);
        $document->setUploadedBy($accessRequest->getUser());
        $document->setProcessed(true);
        $document->setAiMetadata([
            'transparencyCouncil' => $draft->transparencyCouncil,
            'applicableLaw' => $draft->applicableLaw,
            'citedResolutions' => array_map(fn($r) => $r->toArray(), $draft->citedResolutions),
            'citedCriteria' => $draft->citedCriteria,
            'successAnalysis' => $draft->successAnalysis?->toArray(),
            'generatedAt' => (new \DateTime())->format('c'),
        ]);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    private function getAlegacionesContent(AccessRequest $accessRequest): string
    {
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Alegaciones) {
                try {
                    return $this->documentsStorage->read($document->getStoredFilename());
                } catch (\Exception) {
                    return '';
                }
            }
        }

        return '';
    }

    /**
     * @return string[]
     */
    private function getAlegationPoints(AccessRequest $accessRequest): array
    {
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Alegaciones) {
                $metadata = $document->getAiMetadata();
                if (!empty($metadata['alegationPoints']) && is_array($metadata['alegationPoints'])) {
                    return $metadata['alegationPoints'];
                }
            }
        }

        return [];
    }

    private function buildAlegationResponsePrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        array $criteria,
        array $resolutions,
        string $alegacionesContent,
        array $alegationPoints,
        array $documentContents = []
    ): string {
        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $resolutionsText = $this->resolutionRetriever->formatForPrompt($resolutions);

        $alegationPointsText = '';
        if (!empty($alegationPoints)) {
            $alegationPointsText = "## PUNTOS DE ALEGACIÓN DE LA ADMINISTRACIÓN\n\n";
            foreach ($alegationPoints as $i => $point) {
                $alegationPointsText .= sprintf("%d. %s\n", $i + 1, $point);
            }
        }

        $prompt = <<<PROMPT
Eres un abogado especialista en derecho de acceso a información pública en España.
Redacta un escrito de RESPUESTA A LAS ALEGACIONES presentadas por la Administración ante el {$transparencyCouncil}.

El ciudadano presentó una reclamación y la Administración ha respondido con alegaciones defendiendo su posición. Debes rebatir punto por punto las alegaciones de la Administración.

## ESTRUCTURA DEL ESCRITO

### 1. ENCABEZAMIENTO
Escrito dirigido al {$transparencyCouncil} en respuesta a las alegaciones formuladas por {$accessRequest->getPublicBody()->getName()}.

### 2. ANTECEDENTES
Resumen breve de la solicitud original y el proceso de reclamación.

### 3. RESPUESTA A LAS ALEGACIONES
Para CADA punto de alegación de la Administración:
- Cita el argumento de la Administración
- Rebátelo con fundamento jurídico
- Apoya con criterios interpretativos y resoluciones favorables

### 4. CONCLUSIONES Y SOLICITUD
Solicita al {$transparencyCouncil} que desestime las alegaciones y estime la reclamación.

---

## CONTEXTO DE LA SOLICITUD

**Título:** {$accessRequest->getTitle()}

**Descripción:**
{$accessRequest->getDescription()}

**Organismo:** {$accessRequest->getPublicBody()->getName()}

**Ley aplicable:** {$applicableLawName}

---

{$alegationPointsText}

---

{$this->formatDocumentContents($documentContents)}
## CRITERIOS INTERPRETATIVOS RECUPERADOS

{$criteriaText}

---

## RESOLUCIONES FAVORABLES SIMILARES

{$resolutionsText}

---

## REGLAS DE REDACCIÓN

1. DOCUMENTO COMPLETO: El texto debe estar listo para firmar, sin huecos por rellenar
2. SIN PLACEHOLDERS: NUNCA escribas [nombre], [fecha], [espacio para...], [completar], [firma], etc.
3. ESPAÑOL JURÍDICO: Usa lenguaje formal jurídico-administrativo
4. Citar expresamente las resoluciones que fundamenten la argumentación
5. NO incluir encabezado con datos del reclamante
6. REBATIR cada punto de alegación específicamente
7. FORMATO HTML: Devuelve HTML semántico usando ÚNICAMENTE estas etiquetas: <h1>, <p>, <strong>, <em>, <ol>, <ul>, <li>, <blockquote>, <br>, <a>. NO uses <h2>, <h3>, <div>, <span>, <html>, <head>, <body>, estilos inline ni clases CSS. Usa <h1> para cada sección principal. Para subsecciones usa un párrafo con <strong> al inicio en lugar de un encabezado adicional.
8. SUCINTO EN LO FORMAL: Sé breve y directo en cuestiones formales y de procedimiento. Reserva el detalle y la extensión para la argumentación jurídica de fondo.
9. SOLO FUENTES PROPORCIONADAS: Basa tu argumentación EXCLUSIVAMENTE en los criterios interpretativos y resoluciones proporcionados arriba. NO inventes, cites ni menciones ninguna resolución, sentencia, criterio interpretativo o referencia normativa que no aparezca explícitamente en el contexto proporcionado. Si no hay suficientes fuentes, argumenta con los principios generales de la ley aplicable sin fabricar referencias concretas.

Responde ÚNICAMENTE con el HTML del escrito, sin explicaciones adicionales, sin comentarios y sin envolver la respuesta en un bloque de código markdown.
PROMPT;

        return $prompt;
    }

    /**
     * Strip markdown code fences and any model chatter around the HTML body.
     */
    private function sanitizeHtmlResponse(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            return $content;
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:html|HTML)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content ?? '');
            $content = trim($content ?? '');
        }

        return $content;
    }

    /**
     * Returns only the resolutions whose reference is literally quoted in the body of the
     * generated complaint. This is the source of truth for the "Referencias documentales"
     * section of the PDF — we do NOT want to advertise citations that the LLM rejected
     * because they weren't genuinely relevant.
     *
     * Strips HTML tags first so that reference numbers buried in attributes don't count.
     *
     * @return array<int, CitedResolution>
     */
    private function extractCitedResolutions(string $content, array $resolutions): array
    {
        $plain = strip_tags($content);
        $cited = [];

        foreach ($resolutions as $resolution) {
            $reference = $resolution['reference'] ?? '';
            if (!$reference || !str_contains($plain, $reference)) {
                continue;
            }

            $excerpt = (string) ($resolution['summary'] ?? '');
            if ($excerpt === '') {
                $excerpt = mb_substr((string) ($resolution['fullText'] ?? ''), 0, 200);
            }

            $cited[] = new CitedResolution(
                reference: $reference,
                date: $resolution['date'] ?? null,
                excerpt: mb_substr($excerpt, 0, 200),
            );
        }

        return $cited;
    }

    /**
     * Strict detection: the body must contain the literal phrase "Criterio <ID>".
     * A bare ID match is too lax — identifiers are often short enough to collide with
     * unrelated text or hidden in attributes. The prompt instructs the LLM to use this
     * exact wording when it actually cites a criterion.
     *
     * @return array<int, string>
     */
    private function extractCitedCriteria(string $content, array $criteria): array
    {
        $plain = strip_tags($content);
        $cited = [];

        foreach ($criteria as $criterion) {
            $criterionId = $criterion['criterion'] ?? '';
            if (!$criterionId) {
                continue;
            }

            $pattern = '/\bcriterio\s+' . preg_quote($criterionId, '/') . '\b/iu';
            if (preg_match($pattern, $plain) === 1) {
                $cited[] = sprintf('Criterio %s (%d)', $criterionId, $criterion['year']);
            }
        }

        return array_values(array_unique($cited));
    }
}
