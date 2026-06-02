<?php

namespace App\Prompt;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves prompt templates by name. Prefers Langfuse-managed copies (so prompts
 * can be edited without redeploys) and falls back to bundled defaults shipped in
 * `config/prompts/` when Langfuse is unreachable, the prompt is missing, or the
 * SDK is not configured.
 *
 * Templates use Langfuse `{{var}}` placeholders. `compile($name, $vars)` returns
 * a CompiledPrompt: the substituted text plus the Langfuse name/version reference,
 * so traces can link the generation back to the managed prompt.
 *
 * The Langfuse PHP SDK's `getPrompt` does not URL-encode slashes in prompt names,
 * so we go through our own LangfuseAdminClient (which uses `rawurlencode`).
 */
final class PromptStore
{
    /** @var array<string, ?array{template: string, version: ?int}> Fetched-template cache (raw template body + Langfuse version). */
    private array $cache = [];

    public function __construct(
        private readonly BundledPromptLoader $bundled,
        private readonly LangfuseAdminClient $langfuseClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::LANGFUSE_PROMPT_LABEL')]
        private readonly ?string $promptLabel = null,
    ) {
    }

    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public function compile(string $name, array $variables = []): CompiledPrompt
    {
        $fetched = $this->fetchTemplate($name);
        $template = $fetched['template'] ?? $this->bundled->load($name);

        return new CompiledPrompt(
            text: $this->substitute($template, $variables),
            name: $name,
            version: $fetched['version'] ?? null,
        );
    }

    /**
     * @return ?array{template: string, version: ?int}
     */
    private function fetchTemplate(string $name): ?array
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        if (!$this->langfuseClient->isConfigured()) {
            return $this->cache[$name] = null;
        }

        $label = $this->promptLabel !== null && $this->promptLabel !== '' ? $this->promptLabel : 'production';

        try {
            $data = $this->langfuseClient->fetchPrompt($name, $label);
        } catch (\Throwable $e) {
            $this->logger->warning('Langfuse prompt fetch failed; using bundled fallback', [
                'prompt' => $name,
                'error' => $e->getMessage(),
            ]);
            return $this->cache[$name] = null;
        }

        if ($data === null || !isset($data['prompt']) || !is_string($data['prompt'])) {
            return $this->cache[$name] = null;
        }

        return $this->cache[$name] = [
            'template' => $data['prompt'],
            'version' => is_int($data['version'] ?? null) ? $data['version'] : null,
        ];
    }

    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    private function substitute(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }
}
