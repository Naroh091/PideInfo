<?php

namespace App\Prompt;

use Psr\Log\LoggerInterface;

/**
 * Resolves prompt templates by name. Prefers Langfuse-managed copies (so prompts
 * can be edited without redeploys) and falls back to bundled defaults shipped in
 * `config/prompts/` when Langfuse is unreachable, the prompt is missing, or the
 * SDK is not configured.
 *
 * Templates use Langfuse `{{var}}` placeholders. `compile($name, $vars)` returns
 * the substituted string ready to drop into a `ChatRequest::systemPrompt`.
 *
 * The Langfuse PHP SDK's `getPrompt` does not URL-encode slashes in prompt names,
 * so we go through our own LangfuseAdminClient (which uses `rawurlencode`).
 */
final class PromptStore
{
    /** @var array<string, ?string> Compiled-template cache (raw template body). */
    private array $cache = [];

    public function __construct(
        private readonly BundledPromptLoader $bundled,
        private readonly LangfuseAdminClient $langfuseClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public function compile(string $name, array $variables = []): string
    {
        $template = $this->fetchTemplate($name) ?? $this->bundled->load($name);

        return $this->substitute($template, $variables);
    }

    private function fetchTemplate(string $name): ?string
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        if (!$this->langfuseClient->isConfigured()) {
            return $this->cache[$name] = null;
        }

        try {
            $data = $this->langfuseClient->fetchPrompt($name);
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

        return $this->cache[$name] = $data['prompt'];
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
