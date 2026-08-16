<?php

namespace App\Service\AI\Llm;

use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Service\AI\CustomModelClient;
use OpenTelemetry\API\Trace\SpanInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorator that wraps every chat() call (and every retry attempt inside chatJson)
 * with a Langfuse "generation" span.
 */
#[AsDecorator(decorates: LlmClient::class)]
final class TracingLlmClient extends LlmClient
{
    public function __construct(
        #[AutowireDecorated]
        private readonly LlmClient $inner,
        private readonly Tracer $tracer,
        private readonly CustomModelClient $customClient,
        LoggerInterface $logger,
        private readonly ?Security $security = null,
    ) {
        parent::__construct($customClient, $logger); // Rate limiting is in CustomModelClient.
    }

    public function chat(ChatRequest $req): ChatResult
    {
        return $this->tracer->generation(
            name: $req->traceName ?? ('llm.chat' . ($req->label !== null ? ' ' . $req->label : '')),
            attributes: $this->buildRequestAttributes($req),
            fn: fn () => $this->inner->chat($req),
            captureOutput: function (ChatResult $result, SpanInterface $span): void {
                $this->applyResultAttributes($span, $result);
            },
        );
    }

    /**
     * @return \Generator<int, string, void, ChatResult>
     */
    public function chatStream(ChatRequest $req): \Generator
    {
        return yield from $this->tracer->generationStream(
            name: $req->traceName ?? ('llm.chat.stream' . ($req->label !== null ? ' ' . $req->label : '')),
            attributes: $this->buildRequestAttributes($req),
            gen: $this->inner->chatStream($req),
            captureOutput: function (ChatResult $result, SpanInterface $span): void {
                $this->applyResultAttributes($span, $result);
            },
        );
    }

    /**
     * Reimplements LlmClient::chatJson so every retry attempt becomes a sibling
     * generation span instead of being hidden behind a single chat() call.
     *
     * @return array<string, mixed>
     */
    public function chatJson(ChatRequest $req): array
    {
        $lastError = null;
        $tag = $req->label !== null ? sprintf(' [%s]', $req->label) : '';

        for ($attempt = 0; $attempt <= $req->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep(500_000 * $attempt);
            }

            try {
                $content = $this->tracer->generation(
                    name: 'llm.chat' . ($req->label !== null ? ' ' . $req->label : ''),
                    attributes: array_merge(
                        $this->buildRequestAttributes($req),
                        [AttributeKeys::GEN_AI_REQUEST_ATTEMPT => $attempt + 1],
                    ),
                    fn: fn () => $this->inner->chat($req),
                    captureOutput: function (ChatResult $result, SpanInterface $span): void {
                        $this->applyResultAttributes($span, $result);
                    },
                )->content;
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $content = $this->stripCodeFences($content);

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = new \RuntimeException('Invalid JSON from LLM: ' . json_last_error_msg());
                continue;
            }

            if ($req->requiredJsonKeys !== null) {
                $missing = array_diff($req->requiredJsonKeys, array_keys($decoded));
                if (!empty($missing)) {
                    $lastError = new \RuntimeException('LLM response missing required keys: ' . implode(', ', $missing));
                    continue;
                }
            }

            return $decoded;
        }

        throw $lastError ?? new \RuntimeException(sprintf('chatJson%s failed after %d attempts.', $tag, $req->maxRetries + 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestAttributes(ChatRequest $req): array
    {
        $attrs = [
            AttributeKeys::GEN_AI_OPERATION => 'chat',
            AttributeKeys::GEN_AI_SYSTEM => 'openai',
            AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
            AttributeKeys::GEN_AI_REQUEST_MAX_TOKENS => $req->maxOutputTokens,
            AttributeKeys::LANGFUSE_OBSERVATION_LABEL => $req->label,
            AttributeKeys::LANGFUSE_OBSERVATION_INPUT => $this->serializeInput($req),
        ];

        if ($this->customClient->getTemperature() !== null) {
            $attrs[AttributeKeys::GEN_AI_REQUEST_TEMPERATURE] = $this->customClient->getTemperature();
        }

        if ($req->promptRef?->version !== null) {
            $attrs[AttributeKeys::LANGFUSE_OBSERVATION_PROMPT_NAME] = $req->promptRef->name;
            $attrs[AttributeKeys::LANGFUSE_OBSERVATION_PROMPT_VERSION] = $req->promptRef->version;
        }

        $userId = $this->security?->getUser()?->getUserIdentifier();
        if ($userId !== null) {
            $attrs[AttributeKeys::LANGFUSE_USER_ID] = $userId;
        }

        return $attrs;
    }

    private function applyResultAttributes(SpanInterface $span, ChatResult $result): void
    {
        $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, $result->content);
        if ($result->modelId !== null) {
            $span->setAttribute(AttributeKeys::GEN_AI_RESPONSE_MODEL, $result->modelId);
        }
        if ($result->finishReason !== null) {
            $span->setAttribute(AttributeKeys::GEN_AI_RESPONSE_FINISH_REASON, $result->finishReason);
        }
        if ($result->promptTokens !== null) {
            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $result->promptTokens);
        }
        if ($result->completionTokens !== null) {
            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $result->completionTokens);
        }
    }

    private function serializeInput(ChatRequest $req): string
    {
        $payload = ['system' => $req->systemPrompt];

        if (!empty($req->messages)) {
            $payload['messages'] = array_map(
                fn ($m) => ['role' => $m->role, 'content' => $m->content],
                $req->messages,
            );
        }

        if ($req->userParts !== null) {
            $payload['user_parts'] = array_map(
                fn (ContentPart $p) => $p->kind === 'text'
                    ? ['type' => 'text', 'text' => $p->text]
                    : ['type' => $p->kind, 'mime' => $p->mimeType, 'bytes' => strlen((string) $p->base64)],
                $req->userParts,
            );
        } elseif ($req->userText !== null) {
            $payload['user'] = $req->userText;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function stripCodeFences(string $content): string
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json|JSON|html|HTML)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content ?? '');
            $content = trim($content ?? '');
        }
        return $content;
    }
}
