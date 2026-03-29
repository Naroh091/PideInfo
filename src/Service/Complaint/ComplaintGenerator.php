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
use App\Service\AI\ResolutionRetriever;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ComplaintGenerator
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const TRANSPARENCY_COUNCILS = [
        'ES' => 'Consejo de Transparencia y Buen Gobierno',
        'AN' => 'Consejo de Transparencia y Protección de Datos de Andalucía',
        'AR' => 'Consejo de Transparencia de Aragón',
        'AS' => 'Consejo de Transparencia y Buen Gobierno del Principado de Asturias',
        'IB' => 'Comissió per a les Reclamacions d\'Accés a la Informació Pública de les Illes Balears',
        'CN' => 'Comisionado de Transparencia y Acceso a la Información Pública de Canarias',
        'CB' => 'Consejo de Transparencia de Cantabria',
        'CM' => 'Consejo Regional de Transparencia y Buen Gobierno de Castilla-La Mancha',
        'CL' => 'Comisionado de Transparencia de Castilla y León',
        'CT' => 'Comissió de Garantia del Dret d\'Accés a la Informació Pública',
        'EX' => 'Consejo de Transparencia y Participación Ciudadana de Extremadura',
        'GA' => 'Comisionado de Transparencia de Galicia',
        'RI' => 'Consejo de Transparencia de La Rioja',
        'MD' => 'Consejo de Transparencia y Participación de la Comunidad de Madrid',
        'MC' => 'Consejo de la Transparencia de la Región de Murcia',
        'NC' => 'Consejo de Transparencia de Navarra',
        'PV' => 'Comisión Vasca de Acceso a la Información Pública',
        'VC' => 'Consell de Transparència de la Comunitat Valenciana',
    ];

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_BIG_MODEL')]
        private readonly string $geminiModel,
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly SuccessAnalyzer $successAnalyzer,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
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

        $prompt = $this->buildPrompt(
            $accessRequest,
            $transparencyCouncil,
            $applicableLawName,
            $criteria,
            $resolutions,
            $documentContents
        );

        if ($userDirections) {
            $prompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        if (!empty($conversationHistory)) {
            $content = $this->callGeminiApiMultiTurn($prompt, $conversationHistory);
        } else {
            $content = $this->callGeminiApi($prompt);
        }

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
            'reclamacion_%s_%s.txt',
            $accessRequest->getId()->toRfc4122(),
            (new \DateTime())->format('Y-m-d_H-i-s')
        );

        $this->documentsStorage->write($filename, $draft->content);

        $document = new Document();
        $document->setOriginalFilename('Reclamación CTBG.txt');
        $document->setStoredFilename($filename);
        $document->setMimeType('text/plain');
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
        if ($law->isStateLaw()) {
            return self::TRANSPARENCY_COUNCILS['ES'];
        }

        $autonomousCommunity = $law->getAutonomousCommunity();
        if ($autonomousCommunity === null) {
            return self::TRANSPARENCY_COUNCILS['ES'];
        }

        $code = $autonomousCommunity->getCode();

        return self::TRANSPARENCY_COUNCILS[$code] ?? self::TRANSPARENCY_COUNCILS['ES'];
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

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function buildPrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        array $criteria,
        array $resolutions,
        array $documentContents = []
    ): string {
        $status = match (true) {
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'no contestada (silencio administrativo negativo)',
            $accessRequest->isDeadlinePassed() => 'no contestada (silencio administrativo negativo - plazo vencido)',
            default => 'pendiente de resolución',
        };

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No se ha indicado motivo de denegación';

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
- Los criterios interpretativos recuperados (ver abajo)
- Las resoluciones favorables similares (si hay disponibles)

IMPORTANTE: Si usas argumentación de una resolución previa, CITA la resolución expresamente.
Ejemplo: "Como estableció el {$transparencyCouncil} en su Resolución R/0123/2023..."

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

## REGLAS DE REDACCIÓN

1. DOCUMENTO COMPLETO: El texto debe estar listo para firmar, sin huecos por rellenar
2. SIN PLACEHOLDERS: NUNCA escribas [nombre], [fecha], [espacio para...], [completar], [firma], etc.
3. ANTECEDENTES EN PROSA: Los antecedentes deben redactarse en párrafos narrativos, NO en listas con viñetas
4. ESPAÑOL JURÍDICO: Usa lenguaje formal jurídico-administrativo
5. Citar expresamente las resoluciones que fundamenten la argumentación
6. NO incluir encabezado con datos del reclamante (el usuario los añadirá después)
7. FORMATO MARKDOWN: Usa formato Markdown (## para títulos, **negrita**, *cursiva*, etc.)
8. SUCINTO EN LO FORMAL: Sé breve y directo en cuestiones formales y de procedimiento. Reserva el detalle y la extensión para la argumentación jurídica de fondo.
9. SOLO FUENTES PROPORCIONADAS: Basa tu argumentación EXCLUSIVAMENTE en los criterios interpretativos y resoluciones proporcionados arriba. NO inventes, cites ni menciones ninguna resolución, sentencia, criterio interpretativo o referencia normativa que no aparezca explícitamente en el contexto proporcionado. Si no hay suficientes fuentes, argumenta con los principios generales de la ley aplicable sin fabricar referencias concretas.

Responde ÚNICAMENTE con el texto de la reclamación en formato Markdown, sin explicaciones adicionales ni comentarios.
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

        if (!empty($conversationHistory)) {
            $content = $this->callGeminiApiMultiTurn($prompt, $conversationHistory);
        } else {
            $content = $this->callGeminiApi($prompt);
        }

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
7. FORMATO MARKDOWN: Usa formato Markdown (## para títulos, **negrita**, *cursiva*, etc.)
8. SUCINTO EN LO FORMAL: Sé breve y directo en cuestiones formales y de procedimiento. Reserva el detalle y la extensión para la argumentación jurídica de fondo.
9. SOLO FUENTES PROPORCIONADAS: Basa tu argumentación EXCLUSIVAMENTE en los criterios interpretativos y resoluciones proporcionados arriba. NO inventes, cites ni menciones ninguna resolución, sentencia, criterio interpretativo o referencia normativa que no aparezca explícitamente en el contexto proporcionado. Si no hay suficientes fuentes, argumenta con los principios generales de la ley aplicable sin fabricar referencias concretas.

Responde ÚNICAMENTE con el texto del escrito en formato Markdown, sin explicaciones adicionales ni comentarios.
PROMPT;

        return $prompt;
    }

    /**
     * @param ChatMessage[] $conversationHistory
     */
    private function callGeminiApiMultiTurn(string $systemPrompt, array $conversationHistory): string
    {
        $url = sprintf(self::GEMINI_ENDPOINT, $this->geminiModel) . '?key=' . $this->geminiApiKey;

        $contents = [];

        // System prompt as first user turn
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $systemPrompt]],
        ];

        // Model acknowledgment
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => 'Entendido. Procedo a redactar el documento según las instrucciones proporcionadas.']],
        ];

        // Add conversation history
        foreach ($conversationHistory as $message) {
            $contents[] = $message->toGeminiFormat();
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.3,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Gemini API connection error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error: ' . $response);
        }

        $data = json_decode($response, true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function callGeminiApi(string $prompt): string
    {
        $url = sprintf(self::GEMINI_ENDPOINT, $this->geminiModel) . '?key=' . $this->geminiApiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Gemini API connection error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error: ' . $response);
        }

        $data = json_decode($response, true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * @return array<int, CitedResolution>
     */
    private function extractCitedResolutions(string $content, array $resolutions): array
    {
        $cited = [];

        foreach ($resolutions as $resolution) {
            $reference = $resolution['reference'] ?? '';
            if ($reference && str_contains($content, $reference)) {
                $cited[] = new CitedResolution(
                    reference: $reference,
                    date: $resolution['date'] ?? null,
                    excerpt: substr($resolution['text'] ?? '', 0, 200),
                );
            }
        }

        return $cited;
    }

    /**
     * @return array<int, string>
     */
    private function extractCitedCriteria(string $content, array $criteria): array
    {
        $cited = [];

        foreach ($criteria as $criterion) {
            $criterionId = $criterion['criterion'] ?? '';
            if ($criterionId && (
                str_contains($content, $criterionId) ||
                str_contains($content, "Criterio {$criterionId}") ||
                str_contains($content, "criterio {$criterionId}")
            )) {
                $cited[] = sprintf('%s (%d) - %s', $criterionId, $criterion['year'], $criterion['topic']);
            }
        }

        return array_unique($cited);
    }
}
