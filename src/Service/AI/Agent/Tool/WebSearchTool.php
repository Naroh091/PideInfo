<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Camofox\CamofoxMcpClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: web search via the CamoFox MCP server.
 *
 * Opens a browser tab, runs a search on the chosen engine, reads the results
 * page snapshot, closes the tab, and returns the text content so the agent
 * can enrich its context before drafting transparency requests, complaints
 * or allegations.
 *
 * Default engine is duckduckgo (Google blocks residential proxy IPs with
 * CAPTCHAs). If the chosen engine fails, falls back to bing automatically.
 */
#[AsTool(
    name: 'web_search',
    description: 'Busca en internet para enriquecer el contexto antes de redactar. Devuelve el texto de la página de resultados y una lista de URLs encontradas. Úsala cuando necesitas información que NO está en los documentos de la solicitud ni en el corpus de resoluciones: verificar si un organismo ya ha publicado la información solicitada, investigar el contexto legal de un organismo concreto, o comprobar datos públicos relevantes para la redacción. Si necesitas el contenido completo de un resultado, usa visit_url con la URL devuelta.',
)]
final class WebSearchTool
{
    private const ENGINES = ['duckduckgo', 'bing', 'google', 'wikipedia', 'reddit', 'github'];

    public function __construct(
        private readonly CamofoxMcpClient $camofox,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $query  Texto de la búsqueda
     * @param string $engine Motor de búsqueda: duckduckgo (default), bing, google, wikipedia, reddit, github
     */
    public function __invoke(string $query, string $engine = 'duckduckgo'): string
    {
        $this->progress->step("Buscando en internet: {$query}…", 'web_search');

        // Validate engine
        if (!in_array($engine, self::ENGINES, true)) {
            $engine = 'duckduckgo';
        }

        try {
            $result = $this->searchWithFallback($query, $engine);

            if ($result !== null) {
                return $result;
            }

            return "La búsqueda no devolvió resultados legibles. Query: {$query}";
        } catch (\Throwable $e) {
            return sprintf('Error al buscar en internet: %s', $e->getMessage());
        }
    }

    /**
     * Try the requested engine first, fall back to bing, then duckduckgo.
     */
    private function searchWithFallback(string $query, string $engine): ?string
    {
        $engines = [$engine];
        if ($engine !== 'bing') {
            $engines[] = 'bing';
        }
        if ($engine !== 'duckduckgo') {
            $engines[] = 'duckduckgo';
        }

        foreach ($engines as $tryEngine) {
            $result = $this->tryEngine($query, $tryEngine);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function tryEngine(string $query, string $engine): ?string
    {
        try {
            // 1. Create a browser tab
            $createResult = $this->camofox->callTool('create_tab', []);
            $tabId = $this->extractTabId($createResult);

            if ($tabId === null) {
                return null;
            }

            try {
                // 2. Run the search — use web_search for engines with macros,
                //    or navigate directly to the search URL for engines without.
                $searchUrl = $this->getSearchUrl($engine, $query);

                if ($searchUrl !== null) {
                    // Engine without MCP macro: navigate directly
                    $this->camofox->callTool('navigate_and_snapshot', [
                        'tabId' => $tabId,
                        'url' => $searchUrl,
                    ]);
                } else {
                    // Engine with MCP macro (google, youtube, amazon, reddit, wikipedia, etc.)
                    $this->camofox->callTool('web_search', [
                        'tabId' => $tabId,
                        'query' => $query,
                        'engine' => $engine,
                    ]);
                }

                // 3. Read the results page snapshot
                $snapshotResult = $this->camofox->callTool('snapshot', [
                    'tabId' => $tabId,
                ]);
                $snapshotText = $this->extractContentText($snapshotResult);

                // 4. Extract links from the results page
                $linksResult = $this->camofox->callTool('get_links', [
                    'tabId' => $tabId,
                ]);
                $links = $this->extractLinks($linksResult);

                // Check for CAPTCHA / block
                $isBlocked = str_contains($snapshotText, 'unusual traffic')
                    || str_contains($snapshotText, 'Our systems have detected')
                    || str_contains($snapshotText, 'Nuestros sistemas han detectado')
                    || str_contains($snapshotText, 'sorry/index');

                if ($isBlocked) {
                    return null; // Try next engine
                }

                if ($snapshotText === '' && $links === []) {
                    return null;
                }

                $this->progress->step('Búsqueda completada.', 'web_search');

                // 5. Format: snapshot text + structured link list
                $parts = [];
                if ($snapshotText !== '') {
                    if (mb_strlen($snapshotText) > 10000) {
                        $snapshotText = mb_substr($snapshotText, 0, 10000) . "\n\n[…resultados truncados]";
                    }
                    $parts[] = "**Resultados de la búsqueda ({$engine}):**\n\n{$snapshotText}";
                }

                if ($links !== []) {
                    $linkLines = [];
                    $count = 0;
                    foreach ($links as $link) {
                        $url = $link['url'] ?? '';
                        $text = $link['text'] ?? '';
                        if ($url === '' || str_starts_with($url, 'javascript:') || str_starts_with($url, '#')) {
                            continue;
                        }
                        $count++;
                        $linkLines[] = $text !== ''
                            ? sprintf('%d. [%s](%s)', $count, $text, $url)
                            : sprintf('%d. %s', $count, $url);
                        if ($count >= 15) {
                            break;
                        }
                    }
                    if ($linkLines !== []) {
                        $parts[] = "**URLs encontradas (usa visit_url para leer las relevantes):**\n" . implode("\n", $linkLines);
                    }
                }

                return implode("\n\n---\n\n", $parts);
            } finally {
                // 6. Always close the tab
                $this->camofox->callTool('close_tab', ['tabId' => $tabId]);
            }
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the direct search URL for engines that don't have an MCP macro.
     * Returns null for engines that DO have a macro (use web_search tool instead).
     */
    private function getSearchUrl(string $engine, string $query): ?string
    {
        $encodedQuery = urlencode($query);

        return match ($engine) {
            'duckduckgo' => "https://duckduckgo.com/?q={$encodedQuery}",
            'bing' => "https://www.bing.com/search?q={$encodedQuery}",
            'github' => "https://github.com/search?q={$encodedQuery}&type=code",
            'stackoverflow' => "https://stackoverflow.com/search?q={$encodedQuery}",
            // These engines have MCP macros — use web_search tool instead
            'google', 'youtube', 'amazon', 'reddit', 'wikipedia',
            'twitter', 'linkedin', 'facebook', 'instagram', 'tiktok' => null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractTabId(array $result): ?string
    {
        $content = $result['result']['content'] ?? [];
        if (is_array($content) && isset($content[0]['text'])) {
            $text = $content[0]['text'];
            $decoded = json_decode($text, true);
            if (is_array($decoded) && isset($decoded['tabId'])) {
                return (string) $decoded['tabId'];
            }
            if (preg_match('/"tabId"\s*:\s*"([^"]+)"/', $text, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractContentText(array $result): string
    {
        $content = $result['result']['content'] ?? [];
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (isset($item['text'])) {
                    $parts[] = $item['text'];
                }
            }
            return implode("\n", $parts);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, string>>
     */
    private function extractLinks(array $result): array
    {
        $content = $result['result']['content'] ?? [];
        if (!is_array($content) || !isset($content[0]['text'])) {
            return [];
        }

        $text = $content[0]['text'];
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            if (isset($decoded['links']) && is_array($decoded['links'])) {
                return $decoded['links'];
            }
            if (array_is_list($decoded) && isset($decoded[0]['url'])) {
                return $decoded;
            }
        }

        // Fallback: extract URLs from raw text with regex
        $links = [];
        if (preg_match_all('/https?:\/\/[^\s"\'<>\]]+/', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $links[] = ['url' => $url, 'text' => ''];
            }
        }

        return $links;
    }
}
