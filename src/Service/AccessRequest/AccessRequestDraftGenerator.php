<?php

declare(strict_types=1);

namespace App\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\ResolutionRetriever;
use App\Service\Submission\ApplicableLawResolver;

/**
 * Drives the AI-assisted drafting of an access request. Mirrors the role of
 * ComplaintGenerator but for prospective requests:
 *
 *   - generateFirstDraft / rewriteDraft / suggestIdeas / freeChat
 *
 * All four methods return a single JSON payload (not a stream) — drafting
 * a 3 000-char body finishes in 2-4 seconds with our current models, which
 * is well below the threshold where streaming pays off, and lets the
 * frontend treat each turn as a single atomic transition (typewriter-replay
 * the resulting body once received).
 *
 * Resolution context comes from the caller (the controller already loads
 * top-N similar resolutions for the sidebar — passing the same set into
 * the prompt keeps the user and the model looking at the same examples).
 */
final class AccessRequestDraftGenerator
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly ApplicableLawResolver $applicableLawResolver,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     * @return array{title: string, body_html: string, chat_reply: string}
     */
    public function generateFirstDraft(AccessRequest $ar, ?string $userDirections, array $similarResolutions): array
    {
        $vars = $this->commonVars($ar) + [
            'conversation_context' => $this->buildConversationContext($ar),
            'user_directions' => $userDirections ?? '(El usuario no ha escrito nada en este turno. Basa el borrador exclusivamente en la conversación previa.)',
            'similar_resolutions_text' => $this->formatResolutions($similarResolutions),
        ];

        $prompt = $this->promptStore->compile('pideinfo-request-generate-first-draft', $vars);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            size: ModelSize::Big,
            temperature: 0.4,
            jsonSchema: $this->draftSchema(),
            schemaName: 'access_request_first_draft',
            requiredJsonKeys: ['title', 'body_html'],
            maxOutputTokens: 4096,
            label: 'access_request.generate_first_draft',
        ));

        return [
            'title' => $this->trimTitle((string) ($result['title'] ?? '')),
            'body_html' => $this->sanitizeBodyHtml((string) ($result['body_html'] ?? '')),
            'chat_reply' => 'Aquí tienes un primer borrador. Puedes editarlo directamente o pedirme cambios.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     * @return array{title: string, body_html: string, chat_reply: string}
     */
    public function rewriteDraft(AccessRequest $ar, ?string $userDirections, array $similarResolutions): array
    {
        $vars = $this->commonVars($ar) + [
            'conversation_context' => $this->buildConversationContext($ar),
            'current_title' => (string) $ar->getTitle(),
            'current_body_html' => $this->wrapAsHtml((string) $ar->getDescription()),
            'user_directions' => $userDirections ?? '(El usuario no ha escrito indicaciones específicas. Aplica las pautas implícitas en la conversación previa, o un pulido conservador si no hay pautas.)',
            'similar_resolutions_text' => $this->formatResolutions($similarResolutions),
        ];

        $prompt = $this->promptStore->compile('pideinfo-request-rewrite-draft', $vars);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            size: ModelSize::Big,
            temperature: 0.3,
            jsonSchema: $this->draftSchema(includeChatReply: true),
            schemaName: 'access_request_rewrite_draft',
            requiredJsonKeys: ['title', 'body_html', 'chat_reply'],
            maxOutputTokens: 4096,
            label: 'access_request.rewrite_draft',
        ));

        return [
            'title' => $this->trimTitle((string) ($result['title'] ?? '')),
            'body_html' => $this->sanitizeBodyHtml((string) ($result['body_html'] ?? '')),
            'chat_reply' => mb_substr((string) ($result['chat_reply'] ?? 'Borrador actualizado.'), 0, 600),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     * @return array{suggestions: array<int, array{title: string, body: string, source: ?string}>}
     */
    public function suggestIdeas(AccessRequest $ar, ?string $userDirections, array $similarResolutions): array
    {
        $vars = $this->commonVars($ar) + [
            'conversation_context' => $this->buildConversationContext($ar),
            'current_title' => (string) $ar->getTitle(),
            'current_body_html' => $this->wrapAsHtml((string) $ar->getDescription()),
            'user_directions' => $userDirections ?? 'Sugiéreme mejoras sobre el borrador actual teniendo en cuenta lo que ya hemos conversado.',
            'similar_resolutions_text' => $this->formatResolutions($similarResolutions),
        ];

        $prompt = $this->promptStore->compile('pideinfo-request-suggest-ideas', $vars);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            size: ModelSize::Mid,
            temperature: 0.4,
            jsonSchema: $this->suggestionsSchema(),
            schemaName: 'access_request_suggestions',
            requiredJsonKeys: ['suggestions'],
            maxOutputTokens: 1500,
            label: 'access_request.suggest_ideas',
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
                    ? mb_substr($s['source'], 0, 120)
                    : null,
            ];
            if (count($suggestions) >= 4) {
                break;
            }
        }

        return ['suggestions' => $suggestions];
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     * @return array{reply: string}
     */
    public function freeChat(AccessRequest $ar, string $userMessage, array $similarResolutions): array
    {
        $vars = $this->commonVars($ar) + [
            'conversation_context' => $this->buildConversationContext($ar),
            'current_title' => (string) $ar->getTitle(),
            'current_body_html' => $this->wrapAsHtml((string) $ar->getDescription()),
            'similar_resolutions_text' => $this->formatResolutions($similarResolutions),
        ];

        $prompt = $this->promptStore->compile('pideinfo-request-free-chat', $vars);

        $result = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            userText: $userMessage,
            size: ModelSize::Mid,
            temperature: 0.4,
            jsonSchema: ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply']],
            schemaName: 'access_request_free_chat',
            requiredJsonKeys: ['reply'],
            maxOutputTokens: 1024,
            label: 'access_request.free_chat',
        ));

        return [
            'reply' => $this->sanitizeShortHtml((string) ($result['reply'] ?? 'No he podido generar una respuesta. ¿Puedes reformular?')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commonVars(AccessRequest $ar): array
    {
        $law = $ar->getApplicableLaw();
        return [
            'public_body_name' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $law?->getName() ?? 'Ley 19/2013',
            'applicable_law_short_code' => $law?->getShortCode() ?? 'LTAIPBG',
            'deadline_label' => $law instanceof ApplicableLaw
                ? $this->applicableLawResolver->deadlineLabel($law)
                : '1 mes (silencio negativo)',
        ];
    }

    /**
     * Reconstructs the chat history that the user has had with the assistant
     * about this draft, in a flat text format the LLM can read. Critical for
     * "Generar primer borrador" / "Reescribir": without it the model only
     * sees the current turn and ends up making up a topic out of thin air.
     *
     * Capped to the last 12 turns (≈ 6 exchanges) — enough to stay grounded
     * without bloating the prompt.
     */
    private function buildConversationContext(AccessRequest $ar): string
    {
        $history = $ar->getMetadataValue('draft_chat_history');
        if (!is_array($history) || $history === []) {
            return '(Aún no hay conversación previa con el asistente sobre este borrador.)';
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

        if ($lines === []) {
            return '(Aún no hay conversación previa con el asistente sobre este borrador.)';
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $resolutions
     */
    private function formatResolutions(array $resolutions): string
    {
        if ($resolutions === []) {
            return 'No se han encontrado resoluciones suficientemente análogas en el corpus.';
        }
        return $this->resolutionRetriever->formatForPrompt($resolutions);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftSchema(bool $includeChatReply = false): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Asunto de la solicitud, texto plano, máximo 255 caracteres.',
                ],
                'body_html' => [
                    'type' => 'string',
                    'description' => 'Cuerpo del borrador. HTML restringido a <p><b><i><br><ul><li>. Máximo 3000 caracteres efectivos.',
                ],
            ],
            'required' => ['title', 'body_html'],
        ];
        if ($includeChatReply) {
            $schema['properties']['chat_reply'] = [
                'type' => 'string',
                'description' => 'Explicación breve (1-3 frases) de qué se ha cambiado y por qué.',
            ];
            $schema['required'][] = 'chat_reply';
        }
        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'source' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['title', 'body'],
                    ],
                    'minItems' => 1,
                    'maxItems' => 4,
                ],
            ],
            'required' => ['suggestions'],
        ];
    }

    private function trimTitle(string $title): string
    {
        $clean = trim(strip_tags($title));
        return mb_substr($clean, 0, 255);
    }

    private function sanitizeBodyHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $clean = strip_tags($html, ['p', 'b', 'strong', 'i', 'em', 'br', 'ul', 'li']);
        // Hard cap on character count (counting only the visible text length).
        $textLength = mb_strlen(strip_tags($clean));
        if ($textLength <= 3000) {
            return $clean;
        }
        // Truncate from the end of the visible text and keep a single closing <p>.
        $plain = mb_substr(strip_tags($clean), 0, 3000);
        return '<p>' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private function sanitizeShortHtml(string $html): string
    {
        $clean = strip_tags($html, ['b', 'i', 'br']);
        return mb_substr($clean, 0, 1000);
    }

    /**
     * Wraps a plain-text description in a <p> if it doesn't already look
     * like HTML — keeps the prompt readable and the LLM consistent in
     * what it gets to rewrite.
     */
    private function wrapAsHtml(string $body): string
    {
        if ($body === '') {
            return '';
        }
        if (str_contains($body, '<p') || str_contains($body, '<br')) {
            return $body;
        }
        $paragraphs = preg_split('/\n{2,}/', $body) ?: [];
        return implode('', array_map(
            static fn (string $p) => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>',
            $paragraphs,
        ));
    }
}
