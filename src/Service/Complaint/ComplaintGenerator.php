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
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Agent\AgentTurnTrace;
use App\Service\AI\Agent\AgentTurnTraceCapture;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\DoctrinePriorityResolver;
use App\Service\AI\DocumentEmbeddingsRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ModelChoice;
use App\Service\AI\ModelRouter;
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
        private readonly DocumentEmbeddingsRetriever $documentEmbeddingsRetriever,
        private readonly DoctrinePriorityResolver $doctrinePriority,
        private readonly ModelRouter $modelRouter,
        private readonly AgentTurnTraceCapture $traceCapture,
    ) {
    }

    /**
     * Vuelca una generación one-shot (la vía NO agéntica de reclamaciones y
     * respuestas a alegaciones, {@see \App\Controller\ComplaintController}) como
     * traza de destilación.
     *
     * La tarea lleva sufijo `-oneshot` a propósito: estas conversaciones no
     * tienen bucle de herramientas, así que su forma de entrada no es la misma
     * que la del agente y mezclarlas en el mismo corpus enseñaría al modelo a
     * redactar sin buscar doctrina.
     *
     * @param ChatMessage[] $conversationHistory
     */
    private function captureOneShot(
        AccessRequest $accessRequest,
        string $task,
        ModelChoice $model,
        string $systemPrompt,
        array $conversationHistory,
        ?CompiledPrompt $prompt,
        string $content,
    ): void {
        if (!$this->traceCapture->isEnabled()) {
            return;
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($conversationHistory as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $this->traceCapture->capture(new AgentTurnTrace(
            task: $task,
            flow: 'complaint',
            entityId: $accessRequest->getId()->toRfc4122(),
            messages: $messages,
            // Forma de decisión idéntica a la del agente para que la proyección
            // al formato de entrenamiento sea una sola, no dos.
            decision: ['conversational_reply' => '', 'action' => 'generate', 'draft' => ['body_html' => $content]],
            modelRole: $model->role,
            modelName: $model->client->getModel(),
            temperature: $model->client->getTemperature(),
            promptName: $prompt?->name,
            promptVersion: $prompt?->version,
        ));
    }

    /**
     * Retrieve the RAG context (criteria + similar resolutions) for a request,
     * preferring precomputed document embeddings when available. Falls back
     * to the inline string-based query (title + description + …) when no
     * documents have been embedded yet.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function retrieveCriteriaAndResolutions(AccessRequest $accessRequest, int $criteriaTopK = 5, int $resolutionsTopK = 3): array
    {
        $vectors = $this->documentEmbeddingsRetriever->loadVectorsForRequest($accessRequest);
        $priorityOrganismIds = $this->doctrinePriority->priorityOrganismIdsFor($accessRequest);

        // Built up front: the vector path also needs it, as the text for the BM25
        // arm of the hybrid retrieval (the chunk vectors carry no lexical query).
        $contextQuery = $this->buildContextQuery($accessRequest);

        if ($vectors !== []) {
            return [
                $this->criteriaRetriever->retrieveByVectors($vectors, $criteriaTopK, $priorityOrganismIds),
                $this->resolutionRetriever->retrieveSimilarCasesByVectors($vectors, $resolutionsTopK, ['favorable', 'partial'], $priorityOrganismIds, lexicalQuery: $contextQuery),
            ];
        }

        return [
            $this->criteriaRetriever->retrieve($contextQuery, $criteriaTopK, $priorityOrganismIds),
            $this->resolutionRetriever->retrieveSimilarCases($contextQuery, $resolutionsTopK, ['favorable', 'partial'], $priorityOrganismIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rootAttributes(AccessRequest $accessRequest, string $kind): array
    {
        // Misma sesión que la vía agéntica del mismo escrito (`<uuid>:complaint`
        // / `<uuid>:alegation`): sobre un expediente puede haber varias
        // conversaciones distintas, pero TODO lo que produce la reclamación —da
        // igual por qué camino— pertenece a la misma.
        $task = str_starts_with($kind, 'alegaciones') ? 'alegation' : 'complaint';

        return [
            AttributeKeys::LANGFUSE_USER_ID => $accessRequest->getUser()?->getEmail(),
            AttributeKeys::LANGFUSE_SESSION_ID => $accessRequest->getId() . ':' . $task,
            AttributeKeys::LANGFUSE_TAGS => ['one-shot', $task, $kind],
            'agent.task' => $task,
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
                'Cannot generate complaint for this request. Resolution must be denied, partially granted, inadmitted, or in administrative silence.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

        $transparencyCouncil = $this->getTransparencyCouncil($accessRequest->getApplicableLaw());
        $applicableLawName = $accessRequest->getApplicableLaw()->getName();

        [$criteria, $resolutions] = $this->retrieveCriteriaAndResolutions($accessRequest);

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

        $systemPrompt = $prompt->text;
        if ($userDirections) {
            $systemPrompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $model = $this->modelRouter->pick();
        $stream = $this->llmClient->chatStream(new ChatRequest(
            systemPrompt: $systemPrompt,
            messages: $conversationHistory,
            temperature: 1.0,
            maxOutputTokens: 8192,
            label: 'complaint.generate',
            promptRef: $prompt,
            preferTeacher: $model->isTeacher(),
        ));

        foreach ($stream as $delta) {
            yield $delta;
        }

        $content = $this->sanitizeHtmlResponse($stream->getReturn()->content);
        $this->captureOneShot($accessRequest, 'complaint-oneshot', $model, $systemPrompt, $conversationHistory, $prompt, $content);

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
                'Cannot generate complaint for this request. Resolution must be denied, partially granted, inadmitted, or in administrative silence.'
            );
        }

        $successAnalysis = $this->successAnalyzer->analyzeCached($accessRequest);

        $transparencyCouncil = $this->getTransparencyCouncil($accessRequest->getApplicableLaw());
        $applicableLawName = $accessRequest->getApplicableLaw()->getName();

        [$criteria, $resolutions] = $this->retrieveCriteriaAndResolutions($accessRequest);

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

        $systemPrompt = $prompt->text;
        if ($userDirections) {
            $systemPrompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $model = $this->modelRouter->pick();
        $content = $this->llmClient->chat(new ChatRequest(
            systemPrompt: $systemPrompt,
            messages: $conversationHistory,
            temperature: 1.0,
            maxOutputTokens: 8192,
            promptRef: $prompt,
            preferTeacher: $model->isTeacher(),
        ))->content;

        $content = $this->sanitizeHtmlResponse($content);
        $this->captureOneShot($accessRequest, 'complaint-oneshot', $model, $systemPrompt, $conversationHistory, $prompt, $content);

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
        if ($accessRequest->getUser() === null) {
            throw new \LogicException('Anonymous drafts cannot persist complaint documents; claim the request first.');
        }

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
        $document->setContentHash(hash('sha256', $draft->content));
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

    /**
     * Generate EXPONE and SOLICITA plain-text fields for REG submission.
     *
     * Reads the stored HTML complaint document, calls the LLM with the
     * `generate-complaint-reg-fields` prompt, and persists the result in
     * `Document.aiMetadata` under the keys `expone_reg` / `solicita_reg` so
     * subsequent calls for the same document skip the LLM entirely.
     *
     * @return array{expone_reg: string, solicita_reg: string}
     *
     * @throws \RuntimeException when the LLM returns malformed JSON or the
     *                           document content cannot be read from storage.
     */
    public function generateRegFields(Document $complaintDocument, \App\Entity\ComplaintOrganism $organism): array
    {
        // Return cached values when already generated.
        $meta = $complaintDocument->getAiMetadata() ?? [];
        if (!empty($meta['expone_reg']) && !empty($meta['solicita_reg'])) {
            return ['expone_reg' => $meta['expone_reg'], 'solicita_reg' => $meta['solicita_reg']];
        }

        $html = $this->documentsStorage->read($complaintDocument->getStoredFilename());
        $councilName = $organism->getName();

        $prompt = $this->promptStore->compile(
            'pideinfo-complaint-generate-complaint-reg-fields',
            [
                'transparency_council' => $councilName,
                'complaint_html'       => $html,
            ],
        );

        $raw = $this->llmClient->chat(new ChatRequest(
            systemPrompt: $prompt->text,
            messages: [],
            temperature: 1.0,
            promptRef: $prompt,
        ))->content;

        // Strip optional JSON code fence the model may emit despite instructions.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```$/', '', $raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['expone'], $decoded['solicita'])) {
            throw new \RuntimeException(sprintf(
                'LLM returned malformed JSON for REG fields (complaint %s): %s',
                $complaintDocument->getId(),
                substr($raw, 0, 200),
            ));
        }

        $exponeReg  = mb_substr((string) $decoded['expone'],   0, 3900);
        $solicitaReg = mb_substr((string) $decoded['solicita'], 0, 3900);

        // Persist so the next call is a cache hit.
        $complaintDocument->setAiMetadata(array_merge($meta, [
            'expone_reg'   => $exponeReg,
            'solicita_reg' => $solicitaReg,
        ]));
        $this->entityManager->flush();

        return ['expone_reg' => $exponeReg, 'solicita_reg' => $solicitaReg];
    }

    /**
     * Build the system prompt that the unified chat assistant feeds the LLM.
     *
     * Wraps {@see buildPrompt}/{@see buildAlegationResponsePrompt} so the chat
     * flow gets the same legal scaffolding (criteria + resolutions + silence
     * directives) as the one-shot generators, without duplicating the RAG
     * lookups or the prompt template selection here.
     *
     * The caller is responsible for prepending the decision-marker policy on
     * top (see {@see \App\Service\AI\Chat\Composer\ComplaintPromptComposer}).
     *
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function composeChatScaffolding(AccessRequest $accessRequest, string $mode, array $documentContents = []): \App\Prompt\CompiledPrompt
    {
        $transparencyCouncil = $this->getTransparencyCouncil($accessRequest->getApplicableLaw());
        $applicableLawName = $accessRequest->getApplicableLaw()->getName();

        // Chat path: NO pre-injected RAG. The agent uses search_resolutions tool
        // to find relevant resolutions per argument — better quality and more targeted
        // than a single generic top-K search injected into the prompt.
        if ($mode === \App\Service\Complaint\ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            $alegacionesContent = $this->getAlegacionesContent($accessRequest);
            $alegationPoints = $this->getAlegationPoints($accessRequest);
            return $this->buildAlegationResponseChatPrompt(
                $accessRequest,
                $transparencyCouncil,
                $applicableLawName,
                $alegacionesContent,
                $alegationPoints,
                $documentContents,
            );
        }

        return $this->buildChatPrompt(
            $accessRequest,
            $transparencyCouncil,
            $applicableLawName,
            $documentContents,
        );
    }

    public function canGenerateComplaint(AccessRequest $accessRequest): bool
    {
        if ($accessRequest->getStatus() === AccessRequest::STATUS_DELAYED) {
            return true;
        }

        // Any explicit resolution result is grounds for a complaint:
        // - denied / inadmitted / silence: classic refusal
        // - partially_granted: claim against the part not facilitated
        // - granted: the resolution says "yes" but the citizen may claim
        //   the information was never actually delivered (or was delivered
        //   incomplete despite the nominal grant)
        if ($accessRequest->getResolutionResult() !== null) {
            return true;
        }

        if ($accessRequest->isDeadlinePassed() && !in_array($accessRequest->getStatus(), [AccessRequest::STATUS_GRANTED, AccessRequest::STATUS_FINISHED], true)) {
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
        $timeline = "El día {$accessRequest->getSentAt()?->format('d/m/Y')} presenté solicitud de acceso a información pública";
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
    private function buildChatPrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        array $documentContents = [],
    ): CompiledPrompt {
        return $this->buildPrompt($accessRequest, $transparencyCouncil, $applicableLawName, [], [], $documentContents, $this->hasResponseDocument($accessRequest), chatMode: true);
    }

    private function buildPrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        array $criteria,
        array $resolutions,
        array $documentContents = [],
        bool $hasResponseDocument = false,
        bool $chatMode = false,
    ): CompiledPrompt {
        $silencePositive = $accessRequest->getApplicableLaw()->isSilenceIsPositive();
        $silenceLabel = $silencePositive
            ? 'silencio administrativo positivo (derecho adquirido pero no materializado)'
            : 'silencio administrativo negativo';
        $status = match (true) {
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_PARTIALLY_GRANTED => 'estimada parcialmente (concesión parcial)',
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_INADMITTED => 'inadmitida a trámite',
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_GRANTED => 'concedida en la resolución pero información no facilitada',
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_DENIED => 'denegada expresamente',
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
                'documents_block' => $this->formatDocumentContents($documentContents),
            ]);
        }

        // Nota: este campo lo rellena un humano (o queda vacío); NO es la fuente
        // de verdad sobre lo que dijo la administración — los documentos del
        // expediente lo son. El default deja claro al modelo que debe ir a los
        // documentos para identificar límites/causas concretos en lugar de
        // afirmar genéricamente que «no se han invocado».
        $denialReason = $accessRequest->getResolutionNotes()
            ?? '(no hay nota humana; consulta los documentos del expediente para identificar lo que la Administración haya alegado, si algo)';

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

        // Concesión parcial: la queja debe enfocarse sobre la información NO
        // facilitada, no como denegación total. Sustituye el bloque de
        // silencio (mutuamente excluyentes — si hay resolución parcial, no
        // estamos en silencio).
        if ($accessRequest->getResolutionResult() === AccessRequest::RESULT_PARTIALLY_GRANTED) {
            $silenceBlock = <<<'PARTIAL'


## SUPUESTO DE CONCESIÓN PARCIAL

La Administración ha resuelto expresamente facilitando SÓLO PARTE de la información solicitada. La reclamación NO debe argumentarse como una denegación total: hay que delimitar con precisión qué partes de lo solicitado se concedieron y cuáles quedaron sin facilitar.

En la EXPOSICIÓN DE HECHOS:
- Identifica con detalle qué información sí se entregó.
- Identifica qué información solicitada NO se ha facilitado (o se ha facilitado de forma incompleta o ilegible).
- Si la Administración ha motivado la negativa parcial con algún límite del art. 14 o causa de inadmisión del art. 18 Ley 19/2013 (o equivalente autonómico), reprodúcela y rebátela en los Fundamentos Jurídicos.

En los FUNDAMENTOS JURÍDICOS:
- Recuerda que el derecho de acceso es la regla general y los límites son de interpretación restrictiva.
- Argumenta por qué la parte NO facilitada no encaja en los límites alegados (o por qué no se ha alegado ninguno).
- Si la Administración invocó protección de datos, exige que se aplique la disociación o anonimización antes que la denegación.

En la SOLICITUD: pide al {$transparencyCouncil} que estime la reclamación EN LO NO CONCEDIDO y ordene a la Administración la entrega efectiva de la información restante. NO pidas lo que ya se ha facilitado.
PARTIAL;
            $silenceBlock = strtr($silenceBlock, ['{$transparencyCouncil}' => $transparencyCouncil]);
        }

        // Concesión total no materializada: la resolución dice "sí" pero la
        // Administración no ha entregado efectivamente la información.
        if ($accessRequest->getResolutionResult() === AccessRequest::RESULT_GRANTED) {
            $silenceBlock = <<<'GRANTED_PENDING'


## SUPUESTO DE CONCESIÓN TOTAL NO MATERIALIZADA

La Administración ha resuelto expresamente CONCEDIENDO el acceso, pero NO ha entregado efectivamente la información (o lo entregado no se corresponde con lo concedido). El derecho al acceso ya está RECONOCIDO en la resolución; lo que falta es su MATERIALIZACIÓN.

La reclamación NO debe argumentarse como impugnación de una denegación: no la hay. Argumenta la falta de cumplimiento efectivo de una resolución estimatoria.

En la EXPOSICIÓN DE HECHOS:
- Cita la resolución de la Administración y reproduce su contenido estimatorio.
- Describe qué información debió entregarse según esa resolución.
- Detalla qué se ha entregado realmente (nada, parcial, ilegible, formato no útil) y por qué no satisface lo concedido.

En los FUNDAMENTOS JURÍDICOS:
- Recuerda la OBLIGACIÓN DE EJECUTAR los propios actos administrativos firmes (arts. 38 y 39 Ley 39/2015).
- El derecho de acceso fue ya reconocido por la propia Administración: la inejecución constituye un incumplimiento autónomo, no una nueva valoración del fondo.
- Sé BREVE en el fondo: no necesitas argumentar por qué procede el acceso (ya está reconocido). Céntrate en el incumplimiento.

En la SOLICITUD: pide al {$transparencyCouncil} que ORDENE a la Administración el cumplimiento efectivo de su propia resolución estimatoria, con entrega material de la información en el plazo que se determine.
GRANTED_PENDING;
            $silenceBlock = strtr($silenceBlock, ['{$transparencyCouncil}' => $transparencyCouncil]);
        }

        $documentsBlock = $this->formatDocumentContents($documentContents);

        if ($chatMode) {
            return $this->promptStore->compile('pideinfo-complaint-generate-complaint-chat', [
                'transparency_council' => $transparencyCouncil,
                'request_title'        => (string) $accessRequest->getTitle(),
                'request_description'  => (string) $accessRequest->getDescription(),
                'public_body_name'     => $accessRequest->getPublicBody()?->getName() ?? '',
                'external_id'          => (string) $accessRequest->getExternalId(),
                'status'               => $status,
                'denial_reason'        => $denialReason,
                'applicable_law_name'  => $applicableLawName,
                'timeline'             => $timeline,
                'documents_block'      => $documentsBlock,
                'silence_block'        => $silenceBlock,
            ]);
        }

        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $resolutionsText = $this->resolutionRetriever->formatForPrompt($resolutions);

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

        [$criteria, $resolutions] = $this->retrieveCriteriaAndResolutions($accessRequest);

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

        $systemPrompt = $prompt->text;
        if ($userDirections) {
            $systemPrompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $model = $this->modelRouter->pick();
        $stream = $this->llmClient->chatStream(new ChatRequest(
            systemPrompt: $systemPrompt,
            messages: $conversationHistory,
            temperature: 1.0,
            maxOutputTokens: 8192,
            label: 'complaint.alegation_response',
            promptRef: $prompt,
            preferTeacher: $model->isTeacher(),
        ));

        foreach ($stream as $delta) {
            yield $delta;
        }

        $content = $this->sanitizeHtmlResponse($stream->getReturn()->content);
        $this->captureOneShot($accessRequest, 'alegation-oneshot', $model, $systemPrompt, $conversationHistory, $prompt, $content);

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

        [$criteria, $resolutions] = $this->retrieveCriteriaAndResolutions($accessRequest);

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

        $systemPrompt = $prompt->text;
        if ($userDirections) {
            $systemPrompt .= "\n\n## INDICACIONES DEL USUARIO\n\nEl usuario ha dado las siguientes indicaciones específicas para la redacción:\n" . $userDirections;
        }

        $model = $this->modelRouter->pick();
        $content = $this->llmClient->chat(new ChatRequest(
            systemPrompt: $systemPrompt,
            messages: $conversationHistory,
            temperature: 1.0,
            maxOutputTokens: 8192,
            promptRef: $prompt,
            preferTeacher: $model->isTeacher(),
        ))->content;

        $content = $this->sanitizeHtmlResponse($content);
        $this->captureOneShot($accessRequest, 'alegation-oneshot', $model, $systemPrompt, $conversationHistory, $prompt, $content);

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
        if ($accessRequest->getUser() === null) {
            throw new \LogicException('Anonymous drafts cannot persist alegation-response documents; claim the request first.');
        }

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
        $document->setContentHash(hash('sha256', $draft->content));
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

    private function buildAlegationResponseChatPrompt(
        AccessRequest $accessRequest,
        string $transparencyCouncil,
        string $applicableLawName,
        string $alegacionesContent,
        array $alegationPoints,
        array $documentContents = [],
    ): CompiledPrompt {
        $alegationPointsText = $this->buildAlegationPointsText($alegationPoints);

        return $this->promptStore->compile('pideinfo-complaint-generate-alegation-response-chat', [
            'transparency_council'  => $transparencyCouncil,
            'public_body_name'      => $accessRequest->getPublicBody()?->getName() ?? '',
            'request_title'         => (string) $accessRequest->getTitle(),
            'request_description'   => (string) $accessRequest->getDescription(),
            'applicable_law_name'   => $applicableLawName,
            'alegation_points_text' => $alegationPointsText,
            'documents_block'       => $this->formatDocumentContents($documentContents),
        ]);
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
    ): CompiledPrompt {
        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $resolutionsText = $this->resolutionRetriever->formatForPrompt($resolutions);
        $alegationPointsText = $this->buildAlegationPointsText($alegationPoints);

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

    private function buildAlegationPointsText(array $alegationPoints): string
    {
        if (empty($alegationPoints)) {
            return '';
        }
        $text = "## PUNTOS DE ALEGACIÓN DE LA ADMINISTRACIÓN\n\n";
        foreach ($alegationPoints as $i => $point) {
            $text .= sprintf("%d. %s\n", $i + 1, $point);
        }
        return $text;
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
