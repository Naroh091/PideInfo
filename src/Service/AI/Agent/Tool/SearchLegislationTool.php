<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Search\LegislationSearchInterface;
use App\Search\LegislationSearchQuery;
use App\Service\AI\Agent\AgentProgress;
use App\Service\Legal\LegalCitationFormatter;
use App\Service\Legal\TrackedNorms;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: find the precept, when the model knows WHAT it needs but not WHERE it is.
 *
 * BM25 over the articulado of the tracked norms, with Elasticsearch highlights so a long
 * article comes back as the fragment that matched rather than its first paragraph.
 *
 * The sibling tool `read_law_articles` is the one to use when the article is already known —
 * it is exact, it reaches every norm in the BOE, and it never guesses.
 */
#[AsTool(
    name: 'search_legislation',
    description: 'Busca el texto literal de los artículos de la legislación española indexada (Ley 19/2013, LCSP, LPACAP, LRJSP, LBRL, ROF, LJCA, LGS, TREBEP, leyes autonómicas de transparencia…). Devuelve cada artículo con su cita canónica y su ubicación en la norma. Úsala cuando necesites saber qué dice EXACTAMENTE la ley sobre un plazo, un límite, un umbral o una obligación, en lugar de citarlo de memoria.',
)]
final class SearchLegislationTool
{
    /** Hard cap on what goes back to the model; the cut is always made BETWEEN articles. */
    private const MAX_OUTPUT_CHARS = 12_000;

    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly LegislationSearchInterface $search,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $query           Qué buscas, en lenguaje natural o con el término jurídico exacto. Ej.: "umbral económico del contrato menor", "plazo para resolver y sentido del silencio", "derecho de los concejales a obtener información".
     * @param string $boeIds          Filtro opcional: identificadores BOE separados por comas (los da find_law). Ej.: "BOE-A-2017-12902,BOE-A-1985-5392". Vacío = todas las normas indexadas.
     * @param int    $maxResults      Artículos a devolver (1-10, por defecto 5).
     * @param bool   $includeRepealed Incluir artículos derogados (por defecto false; no fundamentes un escrito en un precepto derogado).
     */
    public function __invoke(string $query, string $boeIds = '', int $maxResults = 5, bool $includeRepealed = false): string
    {
        $query = trim($query);
        if ($query === '') {
            return 'Dime qué precepto necesitas encontrar.';
        }

        $this->progress->step('Buscando en el texto de la ley…', 'search_legislation');

        [$ids, $notIndexed] = $this->parseBoeIds($boeIds);

        $result = $this->search->search(new LegislationSearchQuery(
            query: $query,
            boeIds: $ids,
            includeRepealed: $includeRepealed,
            limit: max(1, min($maxResults, self::MAX_RESULTS)),
        ));

        if ($result->isEmpty()) {
            return $this->emptyMessage($notIndexed);
        }

        $blocks = [];
        $used = 0;

        foreach ($result->articles as $article) {
            $id = (string) $article->getId();
            $block = LegalCitationFormatter::block($article, highlights: $result->highlights[$id] ?? null);

            // Truncating mid-article would hand the model half a precept, which it would then
            // quote as if it were the whole thing. Drop the article instead.
            if ($used + mb_strlen($block) > self::MAX_OUTPUT_CHARS && $blocks !== []) {
                break;
            }

            $blocks[] = $block;
            $used += mb_strlen($block);
        }

        $header = sprintf(
            '%d artículo%s aplicable%s (de %d coincidencias):',
            count($blocks),
            count($blocks) === 1 ? '' : 's',
            count($blocks) === 1 ? '' : 's',
            $result->total,
        );

        $footer = [];
        if ($notIndexed !== []) {
            $footer[] = sprintf(
                'Nota: %s no está indexada, así que no se ha buscado en ella. Léela con read_law_articles.',
                implode(', ', $notIndexed),
            );
        }
        if ($result->degraded) {
            $footer[] = 'Nota: el buscador está degradado (sin resaltado); los textos son correctos.';
        }

        return implode("\n\n", array_merge([$header], $blocks, $footer));
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [tracked ids to filter by, ids we cannot search]
     */
    private function parseBoeIds(string $boeIds): array
    {
        $ids = [];
        $notIndexed = [];

        foreach (explode(',', $boeIds) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || !preg_match('/^BOE-A-\d{4}-\d+$/', $candidate)) {
                continue;
            }

            if (TrackedNorms::isTracked($candidate)) {
                $ids[] = $candidate;
            } else {
                // Silently searching the whole corpus after the model asked for one specific
                // norm would be worse than telling it the norm is not searchable.
                $notIndexed[] = $candidate;
            }
        }

        return [$ids, $notIndexed];
    }

    /** @param list<string> $notIndexed */
    private function emptyMessage(array $notIndexed): string
    {
        if ($notIndexed !== []) {
            return sprintf(
                'Ningún artículo indexado coincide, y %s no está indexada. Para leerla usa read_law_articles con su identificador BOE.',
                implode(', ', $notIndexed),
            );
        }

        return 'Ningún artículo indexado coincide con esa búsqueda. Si sabes en qué norma está el precepto, '
            . 'localízala con find_law y léela con read_law_articles; si no, reformula con el término jurídico exacto.';
    }
}
