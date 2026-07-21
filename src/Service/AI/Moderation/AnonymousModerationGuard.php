<?php

declare(strict_types=1);

namespace App\Service\AI\Moderation;

use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dedicated LLM moderation pass for the anonymous drafting flow. Screens both the
 * visitor's message (before it reaches the drafting agent) and the generated draft
 * (before it is persisted/returned), returning a structured {@see ModerationVerdict}.
 *
 * Runs ONLY for anonymous turns — the caller
 * ({@see \App\Controller\AssistantChatController}) gates on `getUser() === null`.
 * On any transport/parse error it fails OPEN (allows the turn) so a moderation
 * outage never breaks legitimate users; the deterministic guardrails (rate limits,
 * strike throttle, withheld egress tools) stay in force regardless.
 *
 * The model is the single self-hosted `CUSTOM_MODEL` (there is no cheaper tier):
 * cost is contained via a tiny output budget and a single retry, not model choice.
 */
class AnonymousModerationGuard
{
    /** Max characters screened per call; drafts/messages beyond this are truncated. */
    private const MAX_INPUT_CHARS = 12_000;

    /** Structured-output schema guaranteeing a parseable verdict. */
    private const VERDICT_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['allowed', 'category'],
        'properties'           => [
            'allowed'  => ['type' => 'boolean'],
            'category' => ['type' => 'string', 'enum' => ModerationVerdict::CATEGORIES],
            'severity' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high']],
        ],
    ];

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'bool:ANON_MODERATION_FAIL_OPEN')]
        private readonly bool $failOpen = true,
    ) {
    }

    public function moderate(string $text, ModerationStage $stage, ?ModerationContext $context = null): ModerationVerdict
    {
        $text = trim($text);
        if ($text === '') {
            return ModerationVerdict::allow();
        }

        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_INPUT_CHARS) . "\n\n[…texto truncado]";
        }

        $prompt = $this->promptStore->compile($stage->promptName(), [
            'text'    => $text,
            'context' => $context?->toPromptBlock() ?? '',
        ]);

        try {
            $raw = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                jsonSchema: self::VERDICT_SCHEMA,
                schemaName: 'moderation_verdict',
                maxOutputTokens: 256,
                maxRetries: 1,
                requiredJsonKeys: ['allowed', 'category'],
                label: 'anon.moderation.' . $stage->value,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Anonymous moderation call failed; failing {mode}', [
                'mode'  => $this->failOpen ? 'open' : 'closed',
                'stage' => $stage->value,
                'error' => $e->getMessage(),
            ]);

            return $this->failOpen
                ? ModerationVerdict::allow('error')
                : ModerationVerdict::block('error');
        }

        return $this->interpret($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function interpret(array $raw): ModerationVerdict
    {
        $allowed = (bool) ($raw['allowed'] ?? true);
        $category = is_string($raw['category'] ?? null) ? $raw['category'] : 'other';
        if (!in_array($category, ModerationVerdict::CATEGORIES, true)) {
            $category = 'other';
        }
        $severity = is_string($raw['severity'] ?? null) ? $raw['severity'] : ($allowed ? 'none' : 'high');

        return new ModerationVerdict($allowed, $category, $severity);
    }
}
