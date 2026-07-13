<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Repository\LegalArticleRepository;

/**
 * Picks the articles worth pasting into the prompt for a transparency law we have no explicit
 * list for — that is, for the 16 autonomous ones.
 *
 * Enumerating 16 sets of article numbers by hand would need a lawyer and would rot with every
 * reform. It is also unnecessary: the autonomous laws copy the structure of the Ley 19/2013
 * almost verbatim, and their rúbricas say what each article is
 * ("Límites al derecho de acceso", "Causas de inadmisión a trámite", "Plazo máximo para
 * resolver y notificar", "Silencio administrativo"). So we select by concept, not by number.
 *
 * Order matters within a concept: the first pattern that hits wins, so the specific ones come
 * before the loose ones. Without that, Cataluña's art. 7 ("Límites a las obligaciones de
 * transparencia" — that is publicidad activa, not access) would be picked instead of its
 * art. 21, which is the one that actually limits the right of access.
 */
final class KeyArticleSelector
{
    /**
     * concept => patterns, most specific first. One article per concept.
     *
     * @var array<string, list<string>>
     */
    private const CONCEPTS = [
        'derecho' => [
            '/derecho de acceso a la informaci[óo]n/iu',
            '/^derecho de acceso/iu',
        ],
        'solicitud' => [
            '/solicitud(?:es)? de acceso/iu',
            '/requisitos de las solicitudes/iu',
            '/^solicitud\b/iu',
            '/presentaci[óo]n de (?:las )?solicitudes/iu',
        ],
        'limites' => [
            '/l[íi]mites?\b.*derecho de acceso/iu',
            '/l[íi]mites?\b.*acceso/iu',
        ],
        'datos' => [
            '/protecci[óo]n de datos personales/iu',
            '/datos personales/iu',
        ],
        'inadmision' => [
            '/causas? de inadmisi[óo]n/iu',
            '/inadmisi[óo]n/iu',
        ],
        'plazo' => [
            '/plazo\b.*(?:resolver|resoluci[óo]n)/iu',
            '/plazo\b.*silencio/iu',
            '/plazo m[áa]ximo/iu',
        ],
        'silencio' => [
            '/silencio administrativo/iu',
            '/sentido del silencio/iu',
        ],
        'resolucion' => [
            '/^resoluci[óo]n\b/iu',
            '/resoluci[óo]n de la solicitud/iu',
        ],
        'reclamacion' => [
            '/reclamaci[óo]n\b/iu',
        ],
    ];

    public function __construct(
        private readonly LegalArticleRepository $articles,
    ) {
    }

    /**
     * @return list<string> article numbers, in document order
     */
    public function select(string $boeId): array
    {
        // findOutline() carries no bodies: matching rúbricas must not drag 90 full articles
        // out of the database.
        $outline = $this->articles->findOutline($boeId);
        if ($outline === []) {
            return [];
        }

        $picked = [];

        foreach (self::CONCEPTS as $concept => $patterns) {
            foreach ($patterns as $pattern) {
                $match = $this->firstMatch($outline, $pattern);

                if ($match !== null) {
                    $picked[$concept] = $match;
                    break;
                }
            }
        }

        // Back to document order: the model reads the law the way the law is written.
        $numbers = array_values($picked);
        usort($numbers, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['number'],
            $numbers,
        )));
    }

    /**
     * @param list<array{anchor: string, kind: string, number: string|null, heading: string|null, breadcrumb: string|null, repealed: bool}> $outline
     *
     * @return array{number: string, position: int}|null
     */
    private function firstMatch(array $outline, string $pattern): ?array
    {
        foreach ($outline as $position => $row) {
            if ($row['kind'] !== 'article' || $row['repealed'] || $row['number'] === null || $row['heading'] === null) {
                continue;
            }

            if (preg_match($pattern, $row['heading']) === 1) {
                return ['number' => $row['number'], 'position' => $position];
            }
        }

        return null;
    }
}
