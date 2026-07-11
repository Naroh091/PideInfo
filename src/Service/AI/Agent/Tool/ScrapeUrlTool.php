<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Crawl4ai\Crawl4aiClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: scrape a URL using Crawl4AI for fast, structured extraction.
 *
 * Unlike visit_url (which uses CamoFox's browser), this tool uses Crawl4AI's
 * headless Chromium for one-shot URL extraction. It's faster and returns
 * cleaner markdown — ideal when you just need to read a page's content
 * without interacting with it (no clicks, no JS interaction, no search).
 *
 * Use this for:
 * - Reading a BOE/BORME page the user attached
 * - Extracting content from a transparency portal
 * - Scraping structured data from a public body's website
 *
 * For interactive browsing (searches, clicking, form filling), use web_search
 * or visit_url (CamoFox) instead.
 */
#[AsTool(
    name: 'scrape_url',
    description: 'Extrae el contenido de una URL de forma rápida y estructurada usando Crawl4AI. Devuelve markdown limpio, links y metadata. Úsala cuando necesitas leer el contenido de una página sin interactuar con ella: BOE, portales de transparencia, webs de organismos. Es más rápida que visit_url para lectura simple. Para búsquedas o interacción (clics, formularios) usa web_search o visit_url.',
)]
final class ScrapeUrlTool
{
    public function __construct(
        private readonly Crawl4aiClient $crawl4ai,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $url URL completa incluyendo el protocolo (https://...)
     */
    public function __invoke(string $url): string
    {
        $this->progress->step("Extrayendo contenido de {$url}…", 'scrape_url');

        try {
            $result = $this->crawl4ai->crawl($url);

            if (isset($result['error'])) {
                return "Error al extraer contenido de {$url}: {$result['error']}";
            }

            $markdown = $result['markdown'] ?? '';

            if ($markdown === '') {
                return "No se pudo extraer contenido de {$url}. La página puede estar vacía o no ser accesible.";
            }

            // Truncate very long pages
            if (mb_strlen($markdown) > 15000) {
                $markdown = mb_substr($markdown, 0, 15000) . "\n\n[…contenido truncado]";
            }

            $this->progress->step('Contenido extraído.', 'scrape_url');

            // Build response with metadata
            $parts = [];

            $metadata = $result['metadata'] ?? [];
            if (!empty($metadata['title'])) {
                $parts[] = "**Título:** {$metadata['title']}";
            }

            $parts[] = $markdown;

            // Add internal links if available
            $links = $result['links'] ?? [];
            if (!empty($links) && is_array($links)) {
                $linkLines = [];
                $count = 0;
                foreach ($links as $link) {
                    $href = $link['href'] ?? '';
                    $text = $link['text'] ?? '';
                    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                        continue;
                    }
                    $count++;
                    $linkLines[] = $text !== ''
                        ? sprintf('%d. [%s](%s)', $count, $text, $href)
                        : sprintf('%d. %s', $count, $href);
                    if ($count >= 10) {
                        break;
                    }
                }
                if (!empty($linkLines)) {
                    $parts[] = "\n**Links de la página:**\n" . implode("\n", $linkLines);
                }
            }

            return implode("\n\n", $parts);
        } catch (\Throwable $e) {
            return sprintf('Error al extraer contenido: %s', $e->getMessage());
        }
    }
}
