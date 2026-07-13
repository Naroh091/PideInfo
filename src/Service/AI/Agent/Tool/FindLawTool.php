<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\LegalNorm;
use App\Repository\LegalNormRepository;
use App\Service\AI\Agent\AgentProgress;
use App\Service\Legal\TrackedNorms;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: turn a law's name into its BOE identifier.
 *
 * The catalogue covers the WHOLE consolidated BOE (12.000+ norms, state and autonomous), not
 * just the ~30 whose articulado we index — so the model can always reach the right norm even
 * when it is one nobody anticipated.
 */
#[AsTool(
    name: 'find_law',
    description: 'Localiza una norma española en el catálogo completo del BOE (estatal y autonómico) a partir de su nombre coloquial, sus siglas o su número oficial, y devuelve su identificador BOE. Necesitas ese identificador para search_legislation y read_law_articles. Úsala SIEMPRE antes de leer o citar una norma cuyo identificador no conozcas.',
)]
final class FindLawTool
{
    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly LegalNormRepository $norms,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $query      Nombre, siglas o número de la norma. Ej.: "Ley de Bases del Régimen Local", "LCSP", "9/2017", "ROF", "ley de transparencia de Cataluña".
     * @param string $scope      Ámbito: "estatal", "autonomico" o "" (ambos, por defecto). Para una comunidad concreta usa su código ELI: es-ct, es-an, es-ga…
     * @param int    $maxResults Número de candidatas (1-10, por defecto 5).
     */
    public function __invoke(string $query, string $scope = '', int $maxResults = 5): string
    {
        $query = trim($query);
        if ($query === '') {
            return 'Dime el nombre, las siglas o el número de la norma que buscas.';
        }

        $this->progress->step('Localizando la norma en el catálogo del BOE…', 'find_law');

        $maxResults = max(1, min($maxResults, self::MAX_RESULTS));

        // The alias is the fastest and least ambiguous path: the model almost always knows the
        // law by its acronym ("LCSP"), and matching that exactly beats any ranking.
        $byAlias = TrackedNorms::byAlias($query);
        if ($byAlias !== null) {
            $norm = $this->norms->findByBoeId($byAlias);
            if ($norm !== null) {
                return "He encontrado 1 norma:\n\n" . $this->describe($norm, 1);
            }
        }

        $norms = $this->norms->searchByName($query, $this->resolveJurisdiction($scope), $maxResults);

        if ($norms === []) {
            return sprintf(
                'No he encontrado ninguna norma con «%s». Prueba con el número oficial ("9/2017"), '
                . 'el nombre completo, o las siglas (LCSP, LBRL, ROF, LPACAP, LJCA, LGS, TREBEP…).',
                $query,
            );
        }

        $lines = [sprintf('He encontrado %d norma%s:', count($norms), count($norms) === 1 ? '' : 's'), ''];

        foreach ($norms as $i => $norm) {
            $lines[] = $this->describe($norm, $i + 1);
        }

        return implode("\n", $lines);
    }

    private function describe(LegalNorm $norm, int $position): string
    {
        $alias = TrackedNorms::alias($norm->getBoeId());

        // The model has to be able to tell two norms with the same number apart, so the rank,
        // the scope and the status are all part of the line, not decoration.
        $facts = array_filter([
            ucfirst(str_replace('_', ' ', $norm->getNormRank() ?? '')),
            $norm->isStateLaw() ? 'Estatal' : 'Autonómica (' . $norm->getJurisdiction() . ')',
            $norm->getStatus() === 'in_force' ? 'vigente' : ($norm->getStatus() ?? ''),
            $norm->getPublicationDate()?->format('Y') ?? '',
        ]);

        $availability = $norm->isTracked() && $norm->hasArticles()
            ? sprintf('%d artículos indexados → puedes usar search_legislation y read_law_articles.', $norm->getArticleCount())
            : 'no indexada → léela con read_law_articles (se parsea el texto consolidado al vuelo).';

        return sprintf(
            "%d. **%s** — %s%s\n   %s · %s\n",
            $position,
            $norm->getBoeId(),
            $norm->getTitle(),
            $alias !== null ? ' (' . $alias . ')' : '',
            implode(' · ', $facts),
            $availability,
        );
    }

    private function resolveJurisdiction(string $scope): ?string
    {
        $scope = strtolower(trim($scope));

        return match (true) {
            $scope === '' => null,
            $scope === 'estatal' => LegalNorm::JURISDICTION_STATE,
            // "autonomico" cannot be expressed as a single jurisdiction value (there are 17),
            // so we simply do not filter and let the ranking decide.
            $scope === 'autonomico' => null,
            (bool) preg_match('/^es-[a-z]{2}$/', $scope) => $scope,
            default => null,
        };
    }
}
