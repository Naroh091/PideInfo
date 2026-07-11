<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Camofox\CamofoxMcpClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: visit a URL and extract its content via the CamoFox MCP server.
 *
 * Opens a browser tab, navigates to the URL, reads the page snapshot, closes
 * the tab, and returns the text content. Used when the user attaches links
 * (or mentions URLs) that should be read to enrich the context before drafting.
 *
 * This is particularly useful for:
 * - Reading a portal de transparencia page to check what information is
 *   already published (relevant for art. 18.1.b defence).
 * - Visiting a BOE or DOGC link the user provided as context.
 * - Checking a public body's website to verify their transparency obligations.
 */
#[AsTool(
    name: 'visit_url',
    description: 'Visita una URL y extrae su contenido de texto. Úsala cuando el usuario adjunta o menciona un enlace que necesitas leer para enriquecer el contexto antes de redactar: portales de transparencia, BOE/DOGC, webs de organismos públicos, etc. Devuelve el texto de la página para que puedas citar datos concretos en la redacción.',
)]
final class VisitUrlTool
{
    public function __construct(
        private readonly CamofoxMcpClient $camofox,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $url URL completa incluyendo el protocolo (https://...)
     */
    public function __invoke(string $url): string
    {
        $this->progress->step("Visitando {$url}…", 'visit_url');

        try {
            // 1. Create a browser tab
            $createResult = $this->camofox->callTool('create_tab', []);
            $tabId = $this->extractTabId($createResult);

            if ($tabId === null) {
                return 'No se pudo abrir una pestaña del navegador para visitar la URL. Inténtalo de nuevo más tarde.';
            }

            try {
                // 2. Navigate and snapshot in one call
                $navResult = $this->camofox->callTool('navigate_and_snapshot', [
                    'tabId' => $tabId,
                    'url' => $url,
                    'timeout' => 15000,
                ]);

                $content = $this->extractSnapshotContent($navResult);

                if ($content === '') {
                    // Fallback: try get_page_html
                    $htmlResult = $this->camofox->callTool('camofox_get_page_html', [
                        'tabId' => $tabId,
                    ]);
                    $content = $this->extractSnapshotContent($htmlResult);
                }

                if ($content === '') {
                    return "No se pudo extraer contenido de {$url}. La página puede estar vacía, requerir autenticación, o no ser accesible.";
                }

                // Truncate very long pages to avoid flooding the agent context
                if (mb_strlen($content) > 15000) {
                    $content = mb_substr($content, 0, 15000) . "\n\n[…contenido truncado]";
                }

                $this->progress->step('Página leída.', 'visit_url');

                return $content;
            } finally {
                // 3. Always close the tab
                $this->camofox->callTool('close_tab', ['tabId' => $tabId]);
            }
        } catch (\Throwable $e) {
            return sprintf('Error al visitar la URL: %s', $e->getMessage());
        }
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
    private function extractSnapshotContent(array $result): string
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
}
