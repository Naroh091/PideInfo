<?php

namespace App\Prompt;

/**
 * Reads prompt templates bundled in `config/prompts/`. The Langfuse name uses a
 * dash-only convention (`pideinfo-{namespace}-{rest}`) because Cloudflare blocks
 * URL paths containing encoded slashes (`%2F`); the GET endpoint to fetch a
 * prompt by name needs the name as a single path segment, so slashes are out.
 *
 * Mapping rule: strip the `pideinfo-` prefix, then split on the FIRST dash to
 * separate the on-disk namespace folder from the rest of the name.
 *
 *   pideinfo-document-analyze-multi              → config/prompts/document/analyze-multi.md
 *   pideinfo-complaint-generate-complaint        → config/prompts/complaint/generate-complaint.md
 *   pideinfo-activity-summary-24h                → config/prompts/activity/summary-24h.md
 *
 * The legacy `pideinfo/{ns}/{rest}` slash form is kept as a fallback so any
 * stray caller keeps working until everyone migrates.
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
        // New convention: pideinfo-{namespace}-{rest}
        if (str_starts_with($name, 'pideinfo-')) {
            $rest = substr($name, strlen('pideinfo-'));
            $firstDash = strpos($rest, '-');
            if ($firstDash !== false) {
                $namespace = substr($rest, 0, $firstDash);
                $remainder = substr($rest, $firstDash + 1);
                return $this->projectDir . '/config/prompts/' . $namespace . '/' . $remainder . '.md';
            }
            // Single-segment under pideinfo (no namespace): drop into root.
            return $this->projectDir . '/config/prompts/' . $rest . '.md';
        }

        // Legacy fallback: pideinfo/{ns}/{rest}
        $relative = $name;
        if (str_starts_with($relative, 'pideinfo/')) {
            $relative = substr($relative, strlen('pideinfo/'));
        }
        return $this->projectDir . '/config/prompts/' . $relative . '.md';
    }
}
