<?php

namespace App\Prompt;

/**
 * Reads prompt templates bundled in `config/prompts/`. The Langfuse name maps
 * to a path under that directory: `pideinfo/document/analyze` →
 * `config/prompts/document/analyze.md`. The leading namespace segment is
 * stripped so `pideinfo/...` keeps the project name out of the filesystem.
 */
final class BundledPromptLoader
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function load(string $name): string
    {
        $path = $this->resolvePath($name);

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Bundled prompt "%s" not found at %s', $name, $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read bundled prompt "%s" at %s', $name, $path));
        }

        return $content;
    }

    public function exists(string $name): bool
    {
        return is_file($this->resolvePath($name));
    }

    public function resolvePath(string $name): string
    {
        $relative = $name;
        if (str_starts_with($relative, 'pideinfo/')) {
            $relative = substr($relative, strlen('pideinfo/'));
        }

        return $this->projectDir . '/config/prompts/' . $relative . '.md';
    }
}
