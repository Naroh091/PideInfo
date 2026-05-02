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
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Prompt\PromptStore;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\ResolutionRetriever;
use App\Service\TransparencyCouncilResolver;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use OpenTelemetry\API\Trace\SpanInterface;

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
        private readonly Tracer $tracer,
        private readonly PromptStore $promptStore,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    private function rootAttributes(AccessRequest $accessRequest, string $kind): array
    {
        return [
            AttributeKeys::LANGFUSE_USER_ID => $accessRequest->getUser()?->getEmail(),
            AttributeKeys::LANGFUSE_SESSION_ID => (string) $accessRequest->getId(),
            AttributeKeys::LANGFUSE_TAGS => ['complaint', $kind],
            'access_request.status' => $accessRequest->getStatus(),
            'access_request.applicable_law' => $accessRequest->getApplicableLaw()?->getName(),
        ];
    }

    /**
     * Trace-level INPUT shown in Langfuse: the user-facing inputs that produced the
     * draft (request facts, directions, chat history, attached documents). Distinct
     * from the per-generation observation input, which is the actual prompt sent to
     * the LLM.
     *
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function buildTraceInput(AccessRequest $accessRequest, array $conversationHistory, ?string $userDirections, array $documentContents): string
    {
        $payload = [
            'access_request' => [
                'id' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
                'description' => $accessRequest->getDescription(),
                'status' => $accessRequest->getStatus(),
                'public_body' => $accessRequest->getPublicBody()?->getName(),
                'applicable_law' => $accessRequest->getApplicableLaw()?->getName(),
                'resolution_notes' => $accessRequest->getResolutionNotes(),
            ],
            'user_directions' => $userDirections,
            'conversation_history' => array_map(
                static fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content],
                $conversationHistory,
            ),
            'documents' => array_map(
                static fn (array $d) => ['name' => $d['name'] ?? null, 'type' => $d['type'] ?? null],
                $documentContents,
            ),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
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
        return $this->tracer->traceRoot(
            name: 'complaint.generate',
            attributes: $this->rootAttributes($accessRequest, 'reclamacion:' . $this->detectComplaintKind($accessRequest)),
            fn: fn () => $this->doGenerate($accessRequest, $conversationHistory, $userDirections, $documentContents),
            captureOutput: function (ComplaintDraft $draft, SpanInterface $span): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, $draft->content);
            },
            traceInput: $this->buildTraceInput($accessRequest, $conversationHistory, $userDirections, $documentContents),
        );
    }

    /**
     * Streaming variant of generate(): yields each HTML delta as it arrives from the
     * model. The Generator's return value (Generator::getReturn()) is the final
     * ComplaintDraft with the sanitized full content and citation extraction.
     *
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    public function generateStream(AccessRequest $accessRequest, array $conversationHistory = [], ?string $userDirections = null, array $documentContents = []): \Generator
    {
        return yield from $this->tracer->traceRootStream(
            name: 'complaint.generate.stream',
            attributes: $this->rootAttributes($accessRequest, 'reclamacion:' . $this->detectComplaintKind($accessRequest)),
            gen: $this->doGenerateStream($accessRequest, $conversationHistory, $userDirections, $documentContents),
            captureOutput: function (ComplaintDraft $draft, SpanInterface $span): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, $draft->content);
            },
            traceInput: $this->buildTraceInput($accessRequest, $conversationHistory, $userDirections, $documentContents),
        );
    }

    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    private function doGenerateStream(AccessRequest $accessRequest, array $conversationHistory, ?string $userDirections, array $documentContents): \Generator
    {
        if (!$this->canGenerateComplaint($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate complaint for this request. Status must be denied or delayed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

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

        $stream = $this->llmClient->chatStream(new ChatRequest(
            systemPrompt: $prompt,
            messages: $conversationHistory,
            size: ModelSize::Big,
            temperature: 0.3,
            maxOutputTokens: 8192,
            label: 'complaint.generate',
        ));

        foreach ($stream as $delta) {
            yield $delta;
        }

        $content = $this->sanitizeHtmlResponse($stream->getReturn()->content);

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

    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function doGenerate(AccessRequest $accessRequest, array $conversationHistory, ?string $userDirections, array $documentContents): ComplaintDraft
    {
        if (!$this->canGenerateComplaint($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate complaint for this request. Status must be denied or delayed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

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
        ))->content;

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

    /**
     * @param array<string, mixed> $extraMetadata Extra keys merged into aiMetadata. Use ['origin' => 'external']
     *                                            for user-pasted complaints; defaults to 'ai' otherwise.
     */
    public function saveComplaint(AccessRequest $accessRequest, ComplaintDraft $draft, array $extraMetadata = []): Document
    {
        // Reuse the existing HTML draft if the user is editing one. Each save
        // used to create a new Document, leaving stale rows behind that
        // confused both the reopen-editor lookup and the docs list.
        $document = $accessRequest->getComplaintDraftDocument();
        $isNew = $document === null;

        $filename = sprintf(
            'reclamacion_%s_%s.html',
            $accessRequest->getId()->toRfc4122(),
            (new \DateTime())->format('Y-m-d_H-i-s')
        );

        $this->documentsStorage->write($filename, $draft->content);

        if ($isNew) {
            $document = new Document();
            $document->setOriginalFilename('Reclamación.html');
            $document->setMimeType('text/html');
            $document->setType(DocumentType::Complaint);
            $document->setAccessRequest($accessRequest);
            $document->setUploadedBy($accessRequest->getUser());
            $document->setProcessed(true);
        } else {
            // Drop the previous file from storage so we don't leak orphan
            // blobs every time the user hits "Guardar".
            $previous = $document->getStoredFilename();
            if ($previous && $this->documentsStorage->fileExists($previous)) {
                try {
                    $this->documentsStorage->delete($previous);
                } catch (\Throwable) {
                    // Non-fatal — the new file is already written.
                }
            }
        }

        $document->setStoredFilename($filename);
        $document->setFileSize(strlen($draft->content));
        $document->setAiMetadata(array_merge([
            'origin' => 'ai',
            'transparencyCouncil' => $draft->transparencyCouncil,
            'applicableLaw' => $draft->applicableLaw,
            'citedResolutions' => array_map(fn($r) => $r->toArray(), $draft->citedResolutions),
            'citedCriteria' => $draft->citedCriteria,
            'successAnalysis' => $draft->successAnalysis?->toArray(),
            'generatedAt' => (new \DateTime())->format('c'),
        ], $extraMetadata));

        if ($isNew) {
            $this->entityManager->persist($document);
        }
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

    private function buildTimeline(AccessRequest $accessRequest): string
    {
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
        return $timeline;
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

    /** Generic prompt with full RAG citations + denial-refutation scaffolding. */
    public const KIND_STANDARD = 'standard';

    /** Compact prompt for silencio administrativo (covers both negative and
     *  positive variants). The template is direction-agnostic; the framing
     *  is supplied by `silenceDirective()` based on the law's silence rule.
     *
     *  Catalan + Aragón cases (silencio positivo) still need a complaint
     *  when the Administración hasn't materialised the access despite the
     *  right being acquired by silence — same shape, different ask. */
    public const KIND_SILENCE = 'silence';

    /**
     * Pick the prompt to use based on the two signals the user spec calls out:
     * the request status and whether the expediente already contains an
     * express resolution from the Administration.
     *
     * - No express resolution + status indicates no answer → compact silence
     *   template (positive or negative — directive is generated separately).
     * - Everything else → existing template (handles express denials with
     *   denial-refutation scaffolding and RAG citations).
     */
    public function detectComplaintKind(AccessRequest $accessRequest): string
    {
        if ($this->hasResponseDocument($accessRequest)) {
            return self::KIND_STANDARD;
        }
        $statusIndicatesNoResponse = $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED
            || $accessRequest->isDeadlinePassed();
        if (!$statusIndicatesNoResponse) {
            return self::KIND_STANDARD;
        }
        return self::KIND_SILENCE;
    }

    /**
     * Inline legal-framing paragraph injected at the top of the silence
     * template. Tells the model whether to argue "desestimación presunta"
     * (silencio negativo) or "derecho adquirido pendiente de materialización"
     * (silencio positivo).
     */
    private function silenceDirective(AccessRequest $accessRequest): string
    {
        $law = $accessRequest->getApplicableLaw();
        $lawName = $law->getName();
        if ($law->isSilenceIsPositive()) {
            return <<<TXT
Conforme a {$lawName}, el silencio administrativo en materia de acceso a información pública tiene sentido **estimatorio**: transcurrido el plazo legal sin respuesta expresa, el derecho de acceso queda **adquirido por silencio positivo**. La Administración no lo ha materializado pese a estar obligada a hacerlo.

Argumenta la reclamación como falta de **materialización** de un derecho **ya reconocido**, no como impugnación de una denegación. En la SOLICITUD, pide al consejo que **declare** el derecho ya adquirido por silencio positivo y **ordene la entrega efectiva** de la información a la Administración.
TXT;
        }
        return <<<TXT
Conforme a {$lawName} (y al artículo 20.4 de la Ley 19/2013 cuando sea estatal), el silencio administrativo en materia de acceso a información pública tiene sentido **desestimatorio**: transcurrido el plazo legal sin respuesta expresa, la solicitud se entiende **denegada por silencio**. Esa denegación presunta carece de motivación, lo que vicia la decisión y justifica por sí solo la estimación de la reclamación.

Argumenta la reclamación como impugnación de la denegación presunta. En la SOLICITUD, pide al consejo que **estime** la reclamación y **ordene** a la Administración entregar la información solicitada.
TXT;
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
        $silencePositive = $accessRequest->getApplicableLaw()->isSilenceIsPositive();
        $silenceLabel = $silencePositive
            ? 'silencio administrativo positivo (derecho adquirido pero no materializado)'
            : 'silencio administrativo negativo';
        $status = match (true) {
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'no contestada (' . $silenceLabel . ')',
            $accessRequest->isDeadlinePassed() => 'plazo vencido sin respuesta (' . $silenceLabel . ')',
            default => 'pendiente de resolución',
        };

        $timeline = $this->buildTimeline($accessRequest);

        // Silence-by-administration cases use a separate, compact template.
        // RAG citations and denial-refutation scaffolding are out of scope
        // when there's literally no resolution to refute. The template is
        // direction-agnostic; the directive paragraph at the top tells the
        // model whether to frame as "denegación presunta" (silencio negativo)
        // or "derecho adquirido pendiente de materialización" (positivo).
        if ($this->detectComplaintKind($accessRequest) === self::KIND_SILENCE) {
            return $this->promptStore->compile('pideinfo-complaint-generate-complaint-silence', [
                'transparency_council' => $transparencyCouncil,
                'request_title' => (string) $accessRequest->getTitle(),
                'public_body_name' => $accessRequest->getPublicBody()?->getName() ?? '',
                'status' => $status,
                'timeline' => $timeline,
                'silence_directive' => $this->silenceDirective($accessRequest),
            ]);
        }

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No se ha indicado motivo de denegación';

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

        $documentsBlock = $this->formatDocumentContents($documentContents);

        return $this->promptStore->compile('pideinfo-complaint-generate-complaint', [
            'transparency_council' => $transparencyCouncil,
            'request_title' => (string) $accessRequest->getTitle(),
            'request_description' => (string) $accessRequest->getDescription(),
            'public_body_name' => $accessRequest->getPublicBody()?->getName() ?? '',
            'external_id' => (string) $accessRequest->getExternalId(),
            'status' => $status,
            'denial_reason' => $denialReason,
            'applicable_law_name' => $applicableLawName,
            'timeline' => $timeline,
            'documents_block' => $documentsBlock,
            'criteria_text' => $criteriaText,
            'resolutions_text' => $resolutionsText,
            'silence_block' => $silenceBlock,
        ]);
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
        return $this->tracer->traceRoot(
            name: 'complaint.alegation_response',
            attributes: $this->rootAttributes($accessRequest, 'alegaciones'),
            fn: fn () => $this->doGenerateAlegationResponse($accessRequest, $conversationHistory, $userDirections, $documentContents),
            captureOutput: function (ComplaintDraft $draft, SpanInterface $span): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, $draft->content);
            },
            traceInput: $this->buildTraceInput($accessRequest, $conversationHistory, $userDirections, $documentContents),
        );
    }

    /**
     * Streaming variant of generateAlegationResponse(): yields HTML deltas as they
     * arrive; the Generator's return value is the final ComplaintDraft.
     *
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    public function generateAlegationResponseStream(AccessRequest $accessRequest, array $conversationHistory = [], ?string $userDirections = null, array $documentContents = []): \Generator
    {
        return yield from $this->tracer->traceRootStream(
            name: 'complaint.alegation_response.stream',
            attributes: $this->rootAttributes($accessRequest, 'alegaciones'),
            gen: $this->doGenerateAlegationResponseStream($accessRequest, $conversationHistory, $userDirections, $documentContents),
            captureOutput: function (ComplaintDraft $draft, SpanInterface $span): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, $draft->content);
            },
            traceInput: $this->buildTraceInput($accessRequest, $conversationHistory, $userDirections, $documentContents),
        );
    }

    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    private function doGenerateAlegationResponseStream(AccessRequest $accessRequest, array $conversationHistory, ?string $userDirections, array $documentContents): \Generator
    {
        if (!$this->canGenerateAlegationResponse($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate alegation response. Complaint status must be reclaimed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

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

        $stream = $this->llmClient->chatStream(new ChatRequest(
            systemPrompt: $prompt,
            messages: $conversationHistory,
            size: ModelSize::Big,
            temperature: 0.3,
            maxOutputTokens: 8192,
            label: 'complaint.alegation_response',
        ));

        foreach ($stream as $delta) {
            yield $delta;
        }

        $content = $this->sanitizeHtmlResponse($stream->getReturn()->content);

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

    /**
     * @param ChatMessage[] $conversationHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function doGenerateAlegationResponse(AccessRequest $accessRequest, array $conversationHistory, ?string $userDirections, array $documentContents): ComplaintDraft
    {
        if (!$this->canGenerateAlegationResponse($accessRequest)) {
            throw new \InvalidArgumentException(
                'Cannot generate alegation response. Complaint status must be reclaimed.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

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
        ))->content;

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

        return $this->promptStore->compile('pideinfo-complaint-generate-alegation-response', [
            'transparency_council' => $transparencyCouncil,
            'public_body_name' => $accessRequest->getPublicBody()?->getName() ?? '',
            'request_title' => (string) $accessRequest->getTitle(),
            'request_description' => (string) $accessRequest->getDescription(),
            'applicable_law_name' => $applicableLawName,
            'alegation_points_text' => $alegationPointsText,
            'documents_block' => $this->formatDocumentContents($documentContents),
            'criteria_text' => $criteriaText,
            'resolutions_text' => $resolutionsText,
        ]);
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
