<?php

declare(strict_types=1);

namespace App\Service\Mesa;

use App\Entity\ComplaintOrganism;
use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\ResolutionRepository;
use App\Prompt\PromptStore;
use App\Service\AI\JudicialHistoryAnnotator;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ResolutionRetriever;
use App\Service\Judgment\JudicialStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * El modo «Preguntar» de la mesa de resoluciones: una pregunta doctrinal en
 * lenguaje natural respondida SOLO con las resoluciones recuperadas del corpus,
 * cada afirmación citando su resolución.
 *
 * Reglas que este servicio hace cumplir por construcción, no por prompt:
 *  - Solo son citables las resoluciones recuperadas en esta misma llamada: las
 *    referencias que el modelo invente se quedan sin enlace y sin ficha.
 *  - Una resolución anulada llega al modelo con su bloque judicial delante
 *    (JudicialHistoryAnnotator, vía el retriever) y, si aun así se cita, la
 *    respuesta lleva la cautela de anulación añadida por el servidor.
 */
final class MesaAnswerer
{
    private const OUTCOMES = ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'];

    /** Candidatas recuperadas y evaluadas de una vez por el cribado batch. */
    private const CANDIDATES = 40;

    /** Las que pasan a lectura completa en la llamada de respuesta. */
    private const MAX_SOURCES = 15;

    public function __construct(
        private readonly ResolutionRetriever $retriever,
        private readonly ComplaintOrganismRepository $organisms,
        private readonly ResolutionRepository $resolutions,
        private readonly JudicialHistoryAnnotator $judicialHistory,
        private readonly LlmClient $llm,
        private readonly PromptStore $prompts,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{
     *     paragraphs: list<string>,
     *     caution: string|null,
     *     sources: list<array{reference: string, id: string, date: string|null, outcomeLabel: string, outcome: string, publicBody: string|null, organism: string|null, summary: string, judicialStatus: string, annulled: bool, url: string}>,
     *     consulted: int,
     *     screened: int,
     * }
     *
     * @throws \RuntimeException cuando no hay material o el modelo no responde
     */
    public function answer(string $question, bool $onlyCtbg = true): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new \RuntimeException('La pregunta está vacía.');
        }

        $candidates = $this->retrieve($question, $onlyCtbg);
        if ($candidates === []) {
            throw new \RuntimeException('No se han encontrado resoluciones pertinentes para esa pregunta. Prueba a reformularla o a ampliar el corpus a todos los consejos.');
        }

        $rows = $this->screen($question, $candidates);

        $decoded = $this->llm->chatJson(new ChatRequest(
            systemPrompt: $this->prompts->compile('pideinfo-mesa-respuesta', [
                'context' => $this->retriever->formatForPrompt($rows),
                'corpus_note' => $onlyCtbg
                    ? 'Las resoluciones proporcionadas proceden principalmente del propio CTBG; si alguna es de otro consejo autonómico, dilo al citarla.'
                    : 'Las resoluciones proporcionadas proceden de los 14 consejos de transparencia; nombra el consejo emisor al citar las que no sean del CTBG.',
            ]),
            userText: $question,
            jsonSchema: self::responseSchema(),
            schemaName: 'mesa_respuesta',
            requiredJsonKeys: ['parrafos', 'citas'],
            maxOutputTokens: 4096,
            label: 'mesa-respuesta',
            traceName: 'mesa-preguntar',
        ));

        $byReference = [];
        foreach ($rows as $row) {
            $byReference[$row['reference']] = $row;
        }

        $paragraphs = array_values(array_filter(array_map(
            static fn ($p): string => trim((string) $p),
            is_array($decoded['parrafos'] ?? null) ? $decoded['parrafos'] : [],
        ), static fn (string $p): bool => $p !== ''));

        if ($paragraphs === []) {
            throw new \RuntimeException('El modelo no ha devuelto una respuesta utilizable. Vuelve a intentarlo.');
        }

        $citedReferences = $this->citedReferences($decoded, $paragraphs, array_keys($byReference));

        $sources = [];
        foreach ($citedReferences as $reference) {
            $sources[] = $this->sourceCard($byReference[$reference]);
        }

        $refToUrl = array_combine($citedReferences, array_map(
            fn (array $s): string => $s['url'],
            $sources,
        ));

        return [
            'paragraphs' => array_map(
                static fn (string $p): string => self::weaveCitations($p, $refToUrl),
                $paragraphs,
            ),
            'caution' => $this->caution($decoded, $sources),
            'sources' => $sources,
            'consulted' => count($rows),
            'screened' => count($candidates),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function retrieve(string $question, bool $onlyCtbg): array
    {
        $ctbgId = $this->organisms
            ->findOneBy(['shortName' => ComplaintOrganism::SHORT_NAME_CTBG])
            ?->getId()?->toRfc4122();

        // Las referencias escritas en la pregunta (RA/0278/2025) se cargan tal
        // cual de la base de datos: el retrieval semántico no puede encontrarlas
        // — el embedding de una referencia no se parece al de su contenido. Van
        // primero y exentas del recorte por consejo: se pidieron por su nombre.
        $explicit = $this->explicitReferenceRows($question);

        // Híbrido SIEMPRE, ignorando RESOLUTION_HYBRID_RETRIEVAL: las preguntas de
        // la mesa suelen ser cortas y literales («uso de formularios normalizados»)
        // y ahí el brazo BM25 es la señal (recall@10 0.46 denso → 0.74 híbrido,
        // docs/search.md). Es el mismo camino evaluado que usa `tipo=ambas`; el
        // peso del brazo léxico en frases largas lo sigue gobernando la env.
        // Con corpus «Solo CTBG» se pide el doble: el recorte por consejo se come
        // parte del pool y aun así queremos acercarnos a las CANDIDATES propias.
        $rows = $this->retriever->retrieveSimilarCases(
            query: $question,
            topK: $onlyCtbg ? self::CANDIDATES * 2 : self::CANDIDATES,
            outcomes: self::OUTCOMES,
            priorityOrganismIds: $ctbgId !== null ? [$ctbgId] : [],
            hybrid: true,
        );

        if ($onlyCtbg && $ctbgId !== null) {
            // El retrieval no filtra por consejo (el boost solo reordena): el recorte al
            // CTBG se hace aquí. Si el corpus propio no da suficiente material, se
            // completa con lo mejor de otros consejos — el prompt pide nombrarlos.
            $ctbg = array_values(array_filter($rows, static fn (array $r): bool => ($r['complaintOrganismId'] ?? null) === $ctbgId));
            $others = array_values(array_filter($rows, static fn (array $r): bool => ($r['complaintOrganismId'] ?? null) !== $ctbgId));
            $rows = array_merge($ctbg, array_slice($others, 0, max(0, 3 - count($ctbg))));
        }

        $merged = [];
        foreach (array_merge($explicit, $rows) as $row) {
            $merged[$row['resolutionId']] ??= $row;
        }

        return array_slice(array_values($merged), 0, self::CANDIDATES);
    }

    /**
     * Cribado de las candidatas: UNA llamada batch las evalúa todas a la vez
     * por resumen y puntos clave y decide, por orden de pertinencia, cuáles
     * ocupan las plazas de lectura completa. A diferencia del cribado del
     * agente de redacción, este conserva la doctrina en dirección contraria.
     *
     * Las referencias pedidas explícitamente en la pregunta no compiten por
     * plaza: entran siempre. Si el cribado falla, se cae al orden del retrieval.
     *
     * @param array<int, array<string, mixed>> $candidates
     *
     * @return array<int, array<string, mixed>>
     */
    private function screen(string $question, array $candidates): array
    {
        $explicit = array_values(array_filter($candidates, static fn (array $r): bool => (bool) ($r['explicit'] ?? false)));
        $pool = array_values(array_filter($candidates, static fn (array $r): bool => !($r['explicit'] ?? false)));

        $slots = self::MAX_SOURCES - count($explicit);
        if ($slots <= 0) {
            return array_slice($explicit, 0, self::MAX_SOURCES);
        }
        if (count($pool) <= $slots) {
            return array_merge($explicit, $pool);
        }

        // Las candidatas sin resumen ni puntos clave no se pueden cribar: se
        // añaden al final con el beneficio de la duda, como hace el agente.
        $screenable = [];
        $blind = [];
        foreach ($pool as $row) {
            if (($row['keypoints'] ?? []) === [] && trim((string) ($row['summary'] ?? '')) === '') {
                $blind[] = $row;
            } else {
                $screenable[] = $row;
            }
        }

        $ordered = array_merge($this->rankByRelevance($question, $screenable), $blind);

        return array_merge($explicit, array_slice($ordered, 0, $slots));
    }

    /**
     * @param array<int, array<string, mixed>> $screenable
     *
     * @return array<int, array<string, mixed>> las pertinentes primero; el resto no vuelve
     */
    private function rankByRelevance(string $question, array $screenable): array
    {
        if ($screenable === []) {
            return [];
        }

        $blocks = [];
        foreach ($screenable as $i => $row) {
            $keypoints = ($row['keypoints'] ?? []) !== []
                ? '- ' . implode("\n- ", $row['keypoints'])
                : '(sin puntos clave registrados)';

            $blocks[] = sprintf(
                "### Resolución %d\n**Referencia:** %s | **Resultado:** %s | **Administración:** %s\n**Resumen:** %s\n**Puntos clave:**\n%s",
                $i + 1,
                $row['reference'] ?? '—',
                $row['outcome'] ?? '—',
                $row['publicBody'] ?? '—',
                $row['summary'] ?? '(sin resumen)',
                $keypoints,
            );
        }

        try {
            $decoded = $this->llm->chatJson(new ChatRequest(
                systemPrompt: $this->prompts->compile('pideinfo-mesa-cribado', [
                    'question' => $question,
                    'total' => (string) count($screenable),
                    'candidates' => implode("\n\n", $blocks),
                ]),
                userText: 'Devuelve el JSON con las resoluciones pertinentes ordenadas.',
                jsonSchema: [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['promising'],
                    'properties' => [
                        'promising' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                ],
                schemaName: 'mesa_cribado',
                requiredJsonKeys: ['promising'],
                maxOutputTokens: 1024,
                label: 'mesa-cribado',
                traceName: 'mesa-preguntar',
            ));
        } catch (\Throwable) {
            return $screenable; // sin cribado, manda el orden del retrieval
        }

        $ranked = [];
        foreach (is_array($decoded['promising'] ?? null) ? $decoded['promising'] : [] as $n) {
            $index = (int) $n - 1;
            if (isset($screenable[$index]) && !isset($ranked[$index])) {
                $ranked[$index] = $screenable[$index];
            }
        }

        return array_values($ranked);
    }

    /**
     * Referencias de resolución escritas literalmente en la pregunta, convertidas
     * en filas con la misma forma que las del retriever (historial judicial
     * incluido) para que el modelo las lea íntegras.
     *
     * @return array<int, array<string, mixed>>
     */
    private function explicitReferenceRows(string $question): array
    {
        $references = self::extractReferences($question);
        if ($references === []) {
            return [];
        }

        $rows = [];
        foreach ($this->resolutions->findByReferenceNumbers($references) as $resolution) {
            $rows[] = [
                'reference' => $resolution->getReferenceNumber(),
                'resolutionId' => $resolution->getId()->toRfc4122(),
                'date' => $resolution->getResolutionDate()?->format('d/m/Y'),
                'outcome' => $resolution->getOutcome(),
                'publicBody' => $resolution->getPublicBodyName(),
                'complaintOrganism' => $resolution->getComplaintOrganism()?->getName(),
                'complaintOrganismId' => $resolution->getComplaintOrganism()?->getId()?->toRfc4122(),
                'summary' => $resolution->getSummary(),
                'keypoints' => $resolution->getKeypoints() ?? [],
                'fullText' => $resolution->getFullText(),
                'score' => null,
                'explicit' => true,
            ];
        }

        return $this->judicialHistory->annotate($rows);
    }

    /**
     * Referencias tipo R/0456/2019, RA/0278/2025, rt/123/2021… normalizadas a
     * MAYÚSCULAS y sin espacios, en orden de aparición y sin duplicados.
     *
     * @return list<string>
     */
    public static function extractReferences(string $text): array
    {
        if (!preg_match_all('~\b([A-Za-z]{1,6})\s*/\s*(\d{1,5})\s*/\s*(\d{4})\b~u', $text, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $references = [];
        foreach ($matches as $match) {
            $references[] = mb_strtoupper($match[1]) . '/' . $match[2] . '/' . $match[3];
        }

        return array_values(array_unique($references));
    }

    /**
     * Referencias citables, en orden de primera aparición: primero las que el
     * modelo declaró en `citas`, después las que aparecen sueltas en el texto.
     * Solo sobreviven las que están entre las resoluciones recuperadas.
     *
     * @param array<string, mixed> $decoded
     * @param list<string> $paragraphs
     * @param list<string> $retrievedReferences
     *
     * @return list<string>
     */
    private function citedReferences(array $decoded, array $paragraphs, array $retrievedReferences): array
    {
        $declared = [];
        foreach (is_array($decoded['citas'] ?? null) ? $decoded['citas'] : [] as $cita) {
            $reference = trim((string) (is_array($cita) ? ($cita['reference'] ?? '') : $cita));
            if ($reference !== '') {
                $declared[] = $reference;
            }
        }

        $text = implode("\n", $paragraphs);
        $inText = array_values(array_filter(
            $retrievedReferences,
            static fn (string $ref): bool => str_contains($text, $ref),
        ));

        $cited = array_values(array_unique(array_merge($declared, $inText)));
        $cited = array_values(array_intersect($cited, $retrievedReferences));

        return array_slice($cited, 0, self::MAX_SOURCES);
    }

    /**
     * @param array<string, mixed> $row fila del retriever (con judicialHistory)
     *
     * @return array{reference: string, id: string, date: string|null, outcomeLabel: string, outcome: string, publicBody: string|null, organism: string|null, summary: string, judicialStatus: string, annulled: bool, url: string}
     */
    private function sourceCard(array $row): array
    {
        $judicialStatus = (string) ($row['judicialHistory']['status'] ?? JudicialStatus::NOT_CHALLENGED);

        return [
            'reference' => (string) $row['reference'],
            'id' => (string) $row['resolutionId'],
            'date' => $row['date'] ?? null,
            'outcome' => (string) ($row['outcome'] ?? ''),
            'outcomeLabel' => Resolution::getOutcomeLabels()[$row['outcome'] ?? ''] ?? (string) ($row['outcome'] ?? ''),
            'publicBody' => $row['publicBody'] ?? null,
            'organism' => $row['complaintOrganism'] ?? null,
            'summary' => mb_substr(trim((string) ($row['summary'] ?? '')), 0, 220),
            'judicialStatus' => $judicialStatus,
            'annulled' => in_array($judicialStatus, JudicialStatus::FILTERS['anulada'], true),
            'url' => $this->urlGenerator->generate('app_resoluciones_show', ['id' => (string) $row['resolutionId']]),
        ];
    }

    /**
     * La cautela del modelo, o la del servidor si el modelo citó doctrina
     * anulada sin avisar. La regla de producto no se delega en el prompt.
     *
     * @param array<string, mixed> $decoded
     * @param list<array{reference: string, judicialStatus: string, annulled: bool}> $sources
     */
    private function caution(array $decoded, array $sources): ?string
    {
        $caution = self::sanitizeCaution((string) ($decoded['cautela'] ?? ''));

        $annulledAgainst = array_values(array_filter(
            $sources,
            static fn (array $s): bool => in_array($s['judicialStatus'], [JudicialStatus::ANNULLED_AGAINST_ACCESS, JudicialStatus::PARTIALLY_ANNULLED], true),
        ));

        if ($caution === '' && $annulledAgainst !== []) {
            $references = implode(', ', array_column($annulledAgainst, 'reference'));
            $caution = sprintf(
                'Entre las resoluciones citadas, %s ha sido anulada total o parcialmente por sentencia firme: no la cites como precedente favorable sin leer antes la sentencia.',
                $references,
            );
        }

        return $caution !== '' ? $caution : null;
    }

    /**
     * Los modelos a veces rellenan el campo con el placeholder en vez de
     * dejarlo vacío («cadena vacía», «no procede»…). Una cautela real es una
     * frase; esto descarta los rellenos conocidos y lo demasiado corto para
     * advertir de nada.
     */
    public static function sanitizeCaution(string $caution): string
    {
        $caution = trim($caution);

        $normalized = mb_strtolower(trim($caution, " \t.,;:-–—\"'«»"));
        $placeholders = [
            '', 'cadena vacía', 'cadena vacia', 'vacío', 'vacio', 'ninguna', 'ninguno',
            'no procede', 'no aplica', 'n/a', 'na', 'null', 'none', 'sin cautela', 'sin advertencias',
        ];

        if (in_array($normalized, $placeholders, true) || mb_strlen($caution) < 20) {
            return '';
        }

        return $caution;
    }

    /**
     * Teje las citas en un párrafo del modelo: escapa el HTML y convierte cada
     * referencia recuperada (con o sin corchetes) en un enlace-chip. Pura y
     * estática a propósito — es la parte con esquinas y se testea sola.
     *
     * @param array<string, string> $refToUrl referencia => URL interna
     */
    public static function weaveCitations(string $paragraph, array $refToUrl): string
    {
        $html = htmlspecialchars($paragraph, ENT_QUOTES);

        // Referencias más largas primero: "R/0456/2019-BIS" antes que "R/0456/2019".
        $references = array_keys($refToUrl);
        usort($references, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        // Dos pasadas con marcadores intermedios: si se insertara el chip
        // directamente, una referencia corta reemplazaría dentro del texto del
        // chip de otra más larga que la contenga.
        $chips = [];
        foreach ($references as $i => $reference) {
            $escapedRef = htmlspecialchars($reference, ENT_QUOTES);
            $token = "\u{E000}cita{$i}\u{E001}";
            $chips[$token] = sprintf(
                '<a class="cita" href="%s">%s</a>',
                htmlspecialchars($refToUrl[$reference], ENT_QUOTES),
                $escapedRef,
            );
            $html = str_replace('[' . $escapedRef . ']', $token, $html);
            $html = str_replace($escapedRef, $token, $html);
            // Deshace el anidado si la referencia apareció con y sin corchetes.
            $html = str_replace('[' . $token . ']', $token, $html);
        }

        return strtr($html, $chips);
    }

    /** @return array<string, mixed> */
    private static function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['parrafos', 'citas'],
            'properties' => [
                'parrafos' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '2-4 párrafos de respuesta. Cita cada afirmación con la referencia entre corchetes, p. ej. [R/0456/2019].',
                ],
                'cautela' => [
                    'type' => 'string',
                    'description' => 'Advertencia redactada si alguna resolución relevante fue anulada o está recurrida. Si no procede, exactamente "" (nunca texto tipo «no procede» o «cadena vacía»).',
                ],
                'citas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['reference'],
                        'properties' => ['reference' => ['type' => 'string']],
                    ],
                    'description' => 'Referencias de las resoluciones realmente usadas en la respuesta.',
                ],
            ],
        ];
    }
}
