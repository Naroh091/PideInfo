<?php

declare(strict_types=1);

namespace App\Service\Complaint;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use App\Enum\DocumentType;
use App\Prompt\PromptStore;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\DocumentEmbeddingsRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\ResolutionRetriever;
use App\Service\TransparencyCouncilResolver;

/**
 * Drives the chat-based authoring flow for complaints and alegation responses.
 *
 * Mirrors AccessRequestDraftGenerator: four user-facing actions (generateFirstDraft,
 * rewriteDraft, suggestIdeas, freeChat), with streaming variants for the two
 * canvas-replacing ones. The actual document-structure prompt scaffolding is
 * delegated to ComplaintGenerator (still the source of truth for the legal shape
 * of a complaint / alegation response). This class only owns the chat-flow
 * concerns: conversation context, current-draft awareness, suggestion/free-chat
 * prompts, and JSON output shaping.
 */
final class ComplaintDraftGenerator
{
    public const MODE_COMPLAINT = 'complaint';
    public const MODE_ALEGATION_RESPONSE = 'alegation_response';

    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly DocumentEmbeddingsRetriever $documentEmbeddingsRetriever,
        private readonly TransparencyCouncilResolver $councilResolver,
    ) {
    }

    /**
     * Stream a fresh complaint / alegation-response draft. Yields HTML deltas;
     * the Generator return is the final ComplaintDraft (sanitized + with citations).
     *
     * @param array<int, array<string, mixed>> $chatHistory Persisted draft_chat_history-style turns
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    public function generateFirstDraftStream(
        AccessRequest $ar,
        string $mode,
        array $chatHistory,
        ?string $userDirections,
        array $documentContents,
    ): \Generator {
        $directions = $this->buildDirectionsBlock(
            mode: $mode,
            chatHistory: $chatHistory,
            userDirections: $userDirections,
            currentBodyHtml: null,
        );

        return yield from $this->delegateStream($ar, $mode, $directions, $documentContents);
    }

    /**
     * Stream a rewrite of the current draft preserving everything not explicitly changed.
     *
     * @param array<int, array<string, mixed>> $chatHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    public function rewriteDraftStream(
        AccessRequest $ar,
        string $mode,
        string $currentBodyHtml,
        array $chatHistory,
        ?string $userDirections,
        array $documentContents,
    ): \Generator {
        $directions = $this->buildDirectionsBlock(
            mode: $mode,
            chatHistory: $chatHistory,
            userDirections: $userDirections,
            currentBodyHtml: $currentBodyHtml,
        );

        return yield from $this->delegateStream($ar, $mode, $directions, $documentContents);
    }

    /**
     * Atomic version of generateFirstDraftStream() — useful for non-streaming clients.
     *
     * @param array<int, array<string, mixed>> $chatHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function generateFirstDraft(
        AccessRequest $ar,
        string $mode,
        array $chatHistory,
        ?string $userDirections,
        array $documentContents,
    ): ComplaintDraft {
        $directions = $this->buildDirectionsBlock(
            mode: $mode,
            chatHistory: $chatHistory,
            userDirections: $userDirections,
            currentBodyHtml: null,
        );

        return $this->delegateAtomic($ar, $mode, $directions, $documentContents);
    }

    /**
     * @param array<int, array<string, mixed>> $chatHistory
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function rewriteDraft(
        AccessRequest $ar,
        string $mode,
        string $currentBodyHtml,
        array $chatHistory,
        ?string $userDirections,
        array $documentContents,
    ): ComplaintDraft {
        $directions = $this->buildDirectionsBlock(
            mode: $mode,
            chatHistory: $chatHistory,
            userDirections: $userDirections,
            currentBodyHtml: $currentBodyHtml,
        );

        return $this->delegateAtomic($ar, $mode, $directions, $documentContents);
    }

    /**
     * Returns 2-3 concrete suggestions the user can choose to apply manually
     * or hand back as a "Generar escrito" instruction.
     *
     * @param array<int, array<string, mixed>> $chatHistory
     * @return array{suggestions: array<int, array{title: string, body: string, source: ?string}>}
     */
    public function suggestIdeas(
        AccessRequest $ar,
        string $mode,
        string $currentBodyHtml,
        array $chatHistory,
        ?string $userDirections,
    ): array {
        [$criteria, $resolutions] = $this->retrieveContext($ar);

        $vars = $this->commonVars($ar) + [
            'current_body_html' => $currentBodyHtml !== '' ? $currentBodyHtml : '(El borrador aún está vacío.)',
            'conversation_context' => $this->buildConversationContext($chatHistory),
            'user_directions' => $userDirections ?? 'Sugiéreme mejoras concretas sobre el borrador actual.',
            'criteria_text' => $this->criteriaRetriever->formatForPrompt($criteria),
            'resolutions_text' => $this->resolutionRetriever->formatForPrompt($resolutions),
            'alegation_points_text' => $this->buildAlegationPointsBlock($ar),
        ];

        $promptName = $mode === self::MODE_ALEGATION_RESPONSE
            ? 'pideinfo-alegation-draft-suggest-ideas'
            : 'pideinfo-complaint-draft-suggest-ideas';

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $this->promptStore->compile($promptName, $vars),
            size: ModelSize::Mid,
            temperature: 1.0,
            requiredJsonKeys: ['suggestions'],
            maxOutputTokens: 1500,
            label: 'complaint_draft.suggest_ideas:' . $mode,
        ));

        $suggestions = [];
        foreach (($result['suggestions'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $suggestions[] = [
                'title' => mb_substr((string) ($s['title'] ?? ''), 0, 120),
                'body' => mb_substr((string) ($s['body'] ?? ''), 0, 400),
                'source' => isset($s['source']) && is_string($s['source']) && $s['source'] !== ''
                    ? mb_substr($s['source'], 0, 160)
                    : null,
            ];
            if (count($suggestions) >= 4) {
                break;
            }
        }

        return ['suggestions' => $suggestions];
    }

    /**
     * Free-form Q&A turn. Doesn't touch the canvas.
     *
     * @param array<int, array<string, mixed>> $chatHistory
     * @return array{reply: string}
     */
    public function freeChat(
        AccessRequest $ar,
        string $mode,
        string $currentBodyHtml,
        array $chatHistory,
        string $userMessage,
    ): array {
        [$criteria, $resolutions] = $this->retrieveContext($ar);

        $vars = $this->commonVars($ar) + [
            'current_body_html' => $currentBodyHtml !== '' ? $currentBodyHtml : '(El borrador aún está vacío.)',
            'conversation_context' => $this->buildConversationContext($chatHistory),
            'criteria_text' => $this->criteriaRetriever->formatForPrompt($criteria),
            'resolutions_text' => $this->resolutionRetriever->formatForPrompt($resolutions),
            'alegation_points_text' => $this->buildAlegationPointsBlock($ar),
        ];

        $promptName = $mode === self::MODE_ALEGATION_RESPONSE
            ? 'pideinfo-alegation-draft-free-chat'
            : 'pideinfo-complaint-draft-free-chat';

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $this->promptStore->compile($promptName, $vars),
            userText: $userMessage,
            size: ModelSize::Mid,
            temperature: 1.0,
            requiredJsonKeys: ['reply'],
            maxOutputTokens: 1024,
            label: 'complaint_draft.free_chat:' . $mode,
        ));

        return [
            'reply' => $this->sanitizeShortHtml((string) ($result['reply'] ?? 'No he podido generar una respuesta. ¿Puedes reformular?')),
        ];
    }

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     * @return \Generator<int, string, void, ComplaintDraft>
     */
    private function delegateStream(AccessRequest $ar, string $mode, string $directions, array $documentContents): \Generator
    {
        if ($mode === self::MODE_ALEGATION_RESPONSE) {
            return yield from $this->complaintGenerator->generateAlegationResponseStream($ar, [], $directions, $documentContents);
        }
        return yield from $this->complaintGenerator->generateStream($ar, [], $directions, $documentContents);
    }

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    private function delegateAtomic(AccessRequest $ar, string $mode, string $directions, array $documentContents): ComplaintDraft
    {
        if ($mode === self::MODE_ALEGATION_RESPONSE) {
            return $this->complaintGenerator->generateAlegationResponse($ar, [], $directions, $documentContents);
        }
        return $this->complaintGenerator->generate($ar, [], $directions, $documentContents);
    }

    /**
     * Composes the chat-aware preamble that ComplaintGenerator slots under
     * "## INDICACIONES DEL USUARIO" — it carries the conversation context, this
     * turn's directions, and (for rewrite) the current draft body the model
     * must preserve unless asked to change it.
     *
     * When the user fires a canvas action (Generate/Rewrite) without typing in
     * the composer, we promote the most recent real user message from the chat
     * to "INDICACIÓN DE ESTE TURNO". Otherwise that precision stays buried in
     * the history and the model treats it as background instead of a directive.
     *
     * @param array<int, array<string, mixed>> $chatHistory
     */
    private function buildDirectionsBlock(string $mode, array $chatHistory, ?string $userDirections, ?string $currentBodyHtml): string
    {
        $parts = [];

        $effectiveDirections = $userDirections;
        $promotedIndex = null;
        if (($effectiveDirections === null || trim($effectiveDirections) === '') && $chatHistory !== []) {
            for ($i = count($chatHistory) - 1; $i >= 0; $i--) {
                $turn = $chatHistory[$i];
                if (!is_array($turn) || ($turn['role'] ?? '') !== 'user') {
                    continue;
                }
                $content = trim((string) ($turn['content'] ?? ''));
                if ($content === '' || $this->isSyntheticUserTurn($content)) {
                    continue;
                }
                $effectiveDirections = $content;
                $promotedIndex = $i;
                break;
            }
        }

        $historyForContext = $chatHistory;
        if ($promotedIndex !== null) {
            // Drop the promoted turn from the conversation context so the model
            // doesn't see it twice (once as directive, once as history).
            array_splice($historyForContext, $promotedIndex, 1);
        }

        $context = $this->buildConversationContext($historyForContext);
        if ($context !== '') {
            $parts[] = "### CONVERSACIÓN PREVIA CON EL ASISTENTE\n\n"
                . "Historial reciente de la conversación entre el usuario y tú sobre este borrador. Es tu fuente principal para entender qué quiere — léelo antes de redactar.\n\n"
                . $context;
        }

        if ($currentBodyHtml !== null && trim(strip_tags($currentBodyHtml)) !== '') {
            $verb = $mode === self::MODE_ALEGATION_RESPONSE ? 'la respuesta a las alegaciones' : 'la reclamación';
            $parts[] = "### BORRADOR ACTUAL (REESCRIBE SOBRE ESTE TEXTO)\n\n"
                . "El siguiente texto es {$verb} en la que el usuario está trabajando. Tu tarea NO es redactar una nueva desde cero: REESCRÍBELA aplicando las indicaciones de este turno y de la conversación previa, conservando intactas las partes que no se piden cambiar. Devuelve siempre el documento completo, no un fragmento.\n\n"
                . $currentBodyHtml;
        }

        if ($effectiveDirections !== null && trim($effectiveDirections) !== '') {
            $parts[] = "### INDICACIÓN DE ESTE TURNO\n\n" . trim($effectiveDirections);
        } elseif ($currentBodyHtml === null && $context === '') {
            $parts[] = "### INDICACIÓN DE ESTE TURNO\n\n(El usuario no ha escrito indicaciones; redacta un borrador con argumentos por defecto adecuados al expediente.)";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Synthetic placeholder strings the controller writes to chat history when the
     * user fires a button-only action ("Reescribir", "Generar", "Sugerir") without
     * typing in the composer. We must not promote these to "INDICACIÓN DE ESTE
     * TURNO" — they're cosmetic transcript markers, not real user instructions.
     */
    private function isSyntheticUserTurn(string $content): bool
    {
        return in_array($content, [
            'Reescribir borrador',
            'Generar primer borrador',
            'Sugerir ideas',
        ], true);
    }

    /**
     * Reconstructs the chat history into a flat readable text the LLM can ingest.
     * Mirrors the format used by AccessRequestDraftGenerator.
     *
     * @param array<int, array<string, mixed>> $history
     */
    private function buildConversationContext(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $turns = array_slice($history, -12);
        $lines = [];
        foreach ($turns as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = ($turn['role'] ?? '') === 'user' ? 'Usuario' : 'Asistente';
            $kind = $turn['kind'] ?? 'text';

            if ($kind === 'suggestions') {
                $items = is_array($turn['items'] ?? null) ? $turn['items'] : [];
                if ($items === []) {
                    continue;
                }
                $bullets = [];
                foreach ($items as $item) {
                    $title = trim((string) ($item['title'] ?? ''));
                    $body = trim((string) ($item['body'] ?? ''));
                    if ($title === '' && $body === '') {
                        continue;
                    }
                    $bullets[] = '  · ' . ($title !== '' ? $title . ': ' : '') . $body;
                }
                if ($bullets === []) {
                    continue;
                }
                $lines[] = $role . ' (sugerencias):' . "\n" . implode("\n", $bullets);
                continue;
            }

            $content = trim(strip_tags((string) ($turn['content'] ?? '')));
            if ($content === '') {
                continue;
            }
            $lines[] = $role . ': ' . $content;
        }

        return $lines === [] ? '' : implode("\n\n", $lines);
    }

    /**
     * Common Twig vars for suggest_ideas / free_chat prompts.
     *
     * @return array<string, mixed>
     */
    private function commonVars(AccessRequest $ar): array
    {
        $law = $ar->getApplicableLaw();
        $council = $law !== null ? $this->councilResolver->forLaw($law) : 'el Consejo de Transparencia';

        $silenceLabel = $law !== null && $law->isSilenceIsPositive()
            ? 'silencio administrativo positivo (derecho adquirido pero no materializado)'
            : 'silencio administrativo negativo';
        $status = match (true) {
            $ar->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $ar->getStatus() === AccessRequest::STATUS_DELAYED => 'no contestada (' . $silenceLabel . ')',
            $ar->isDeadlinePassed() => 'plazo vencido sin respuesta (' . $silenceLabel . ')',
            default => 'pendiente de resolución',
        };

        return [
            'transparency_council' => $council,
            'request_title' => (string) $ar->getTitle(),
            'public_body_name' => $ar->getPublicBody()?->getName() ?? '',
            'applicable_law_name' => $law?->getName() ?? 'Ley 19/2013',
            'status' => $status,
            'denial_reason' => $ar->getResolutionNotes() ?? 'No se ha indicado motivo de denegación',
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function retrieveContext(AccessRequest $ar): array
    {
        $vectors = $this->documentEmbeddingsRetriever->loadVectorsForRequest($ar);

        if ($vectors !== []) {
            return [
                $this->criteriaRetriever->retrieveByVectors($vectors, 3),
                $this->resolutionRetriever->retrieveSimilarCasesByVectors($vectors, 3),
            ];
        }

        $query = trim(($ar->getTitle() ?? '') . '. ' . ($ar->getDescription() ?? ''));
        if ($query === '.') {
            $query = $ar->getPublicBody()?->getName() ?? '';
        }

        return [
            $this->criteriaRetriever->retrieve($query, 3),
            $this->resolutionRetriever->retrieveSimilarCases($query, 3),
        ];
    }

    private function buildAlegationPointsBlock(AccessRequest $ar): string
    {
        foreach ($ar->getDocuments() as $document) {
            if ($document->getType() !== DocumentType::Alegaciones) {
                continue;
            }
            $metadata = $document->getAiMetadata();
            $points = $metadata['alegationPoints'] ?? null;
            if (!is_array($points) || $points === []) {
                continue;
            }
            $lines = ["## PUNTOS DE ALEGACIÓN DE LA ADMINISTRACIÓN\n"];
            foreach ($points as $i => $point) {
                $lines[] = sprintf('%d. %s', $i + 1, $point);
            }
            return implode("\n", $lines);
        }
        return '';
    }

    private function sanitizeShortHtml(string $html): string
    {
        $clean = strip_tags($html, ['b', 'i', 'br']);
        return mb_substr($clean, 0, 1200);
    }
}
