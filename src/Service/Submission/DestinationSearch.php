<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AgentTask;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Service\AI\RegDestinationRetriever;
use Symfony\Component\Uid\Uuid;

/**
 * Unified, paginated, faceted search over submission destinations for the
 * picker modal: REG units (cross-body) ∪ Portal/AGE bodies, matched by name and
 * DIR3 code. Keyword hits are relevance-ranked (see the repository's `match_rank`):
 * strong literal matches (exact name / prefix / all tokens in the name) sort to
 * the top, then — on the first page, when literal matches are scarce — a bounded
 * set of semantically-similar REG destinations from the vector store
 * ({@see RegDestinationRetriever}), then weaker facet-only keyword matches. The
 * semantic boost degrades silently to keyword-only when the store is empty.
 * See docs/request-workflow.md.
 */
final class DestinationSearch
{
    /** Prepend semantic hits only when literal REG hits on page 0 fall below this. */
    private const LITERAL_THRESHOLD = 5;
    /** Keyword hits at or below this match_rank (exact/prefix/all-tokens-in-name) outrank semantic hits. */
    private const STRONG_MATCH_RANK = 2;
    /** Max semantic hits prepended on page 0. */
    private const SEMANTIC_CAP = 5;
    /** Over-fetch from the vector store so post-filtering still yields enough. */
    private const SEMANTIC_TOPK = 15;

    public function __construct(
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly RegDestinationRetriever $regDestinationRetriever,
    ) {
    }

    public function search(string $query, DestinationSearchFilters $filters, int $limit = 20, int $offset = 0): DestinationSearchResult
    {
        $q = trim($query);
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $nivelRaw = ($filters->nivel !== null && $filters->nivel !== '')
            ? RegAdministrationLevel::nivelFor($filters->nivel) // null when the UI key is invalid → all levels
            : null;
        $comunidad = ($filters->comunidad !== null && $filters->comunidad !== '') ? $filters->comunidad : null;
        $provincia = ($filters->provincia !== null && $filters->provincia !== '') ? $filters->provincia : null;

        $rows = $this->regDestinationRepository->searchUnifiedCandidates(
            $q,
            $nivelRaw,
            $comunidad,
            $provincia,
            $limit + 1,
            $offset,
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        /** @var array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache */
        $lawCache = [];

        // Split the keyword hits by relevance: strong literal matches (exact name,
        // name prefix, or all query tokens in the name — match_rank <= 2) rank above
        // the semantic suggestions; weak facet-only matches (match_rank 3) fall below.
        $strong = [];
        $weak = [];
        $literalReg = 0;
        foreach ($rows as $row) {
            $candidate = $this->candidateFromRow($row, $lawCache);
            if ((int) $row['match_rank'] <= self::STRONG_MATCH_RANK) {
                $strong[] = $candidate;
            } else {
                $weak[] = $candidate;
            }
            if ($candidate->kind === DestinationCandidate::KIND_REG) {
                ++$literalReg;
            }
        }

        $prepended = [];
        if ($offset === 0 && $q !== '' && $literalReg < self::LITERAL_THRESHOLD) {
            $prepended = $this->semanticBoost($q, $nivelRaw, $comunidad, $provincia, $lawCache);
        }

        $items = array_merge($strong, $prepended, $weak);

        return new DestinationSearchResult($items, $hasMore, $offset + $limit, count($items));
    }

    /**
     * @param array<string, mixed>                                                              $row
     * @param array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache
     */
    private function candidateFromRow(array $row, array &$lawCache): DestinationCandidate
    {
        $kind = (string) $row['kind'];
        $publicBodyId = (string) $row['public_body_id'];
        $nivelRaw = (string) $row['nivel_administracion'];

        return new DestinationCandidate(
            kind: $kind,
            publicBodyId: $publicBodyId,
            regDestinationId: $row['reg_destination_id'] !== null ? (string) $row['reg_destination_id'] : null,
            name: (string) $row['name'],
            displayLabel: (string) $row['display_label'],
            dir3Code: $row['dir3_code'] !== null ? (string) $row['dir3_code'] : null,
            comunidad: $row['comunidad'] !== null ? (string) $row['comunidad'] : null,
            provincia: $row['provincia'] !== null ? (string) $row['provincia'] : null,
            nivelAdministracion: $nivelRaw,
            nivelLabel: RegAdministrationLevel::labelForNivel($nivelRaw),
            channel: $this->channelFor($kind),
            channelLabel: $this->channelLabelFor($kind),
            applicableLaw: $this->lawArrayForBodyId($publicBodyId, $lawCache),
            oficinaDir3: $row['oficina_dir3'] !== null ? (string) $row['oficina_dir3'] : null,
            oficinaName: $row['oficina_name'] !== null ? (string) $row['oficina_name'] : null,
            raizDir3: $row['raiz_dir3'] !== null ? (string) $row['raiz_dir3'] : null,
            raizName: $row['raiz_name'] !== null ? (string) $row['raiz_name'] : null,
        );
    }

    /**
     * @param array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache
     *
     * @return list<DestinationCandidate>
     */
    private function semanticBoost(string $q, ?string $nivelRaw, ?string $comunidad, ?string $provincia, array &$lawCache): array
    {
        $hits = $this->regDestinationRetriever->search($q, $comunidad, $provincia, self::SEMANTIC_TOPK);

        $out = [];
        $seen = [];
        foreach ($hits as $hit) {
            /** @var RegDestination $dest */
            $dest = $hit['destination'];

            // Skip anything that would already surface in the keyword stream
            // (this or a later page) — keeps pagination dupe-free.
            if ($this->matchesKeyword($dest, $q)) {
                continue;
            }
            // Mirror the SQL grain's exclusions: dormant units + nivel facet.
            if ($dest->getSubmissionTarget()->getTransparencyPortalAmbId() !== null) {
                continue;
            }
            if ($nivelRaw !== null && $dest->getNivelAdministracion() !== $nivelRaw) {
                continue;
            }

            $id = $dest->getId()->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $out[] = $this->candidateFromEntity($dest, $hit['score'] ?? null, $lawCache);
            if (count($out) >= self::SEMANTIC_CAP) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache
     */
    private function candidateFromEntity(RegDestination $dest, ?float $score, array &$lawCache): DestinationCandidate
    {
        $target = $dest->getSubmissionTarget();
        $nivelRaw = (string) $dest->getNivelAdministracion();

        return new DestinationCandidate(
            kind: DestinationCandidate::KIND_REG,
            publicBodyId: $target->getId()->toRfc4122(),
            regDestinationId: $dest->getId()->toRfc4122(),
            name: $dest->getName(),
            displayLabel: $dest->getDisplayLabel(),
            dir3Code: $dest->getDir3Code(),
            comunidad: $dest->getComunidad(),
            provincia: $dest->getProvincia(),
            nivelAdministracion: $nivelRaw,
            nivelLabel: RegAdministrationLevel::labelForNivel($nivelRaw),
            channel: AgentTask::TYPE_SUBMIT_REQUEST_REG,
            channelLabel: ChannelResolver::BADGE_REG,
            applicableLaw: $this->lawArrayForBody($target, $lawCache),
            oficinaDir3: $dest->getOficinaDir3(),
            oficinaName: $dest->getOficinaName(),
            raizDir3: $dest->getPublicBody()->getDir3Code(),
            raizName: $dest->getPublicBody()->getName(),
            semantic: true,
            score: $score,
        );
    }

    private function matchesKeyword(RegDestination $dest, string $q): bool
    {
        $tokens = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        // Mirror the SQL predicate: every token must appear in name / dir3 /
        // comunidad / provincia (accent-folded). Only when ALL tokens match does
        // the hit already live in the keyword stream → skip it in the boost.
        $haystack = $this->fold($dest->getName())
            . ' ' . mb_strtolower($dest->getDir3Code())
            . ' ' . $this->fold((string) $dest->getComunidad())
            . ' ' . $this->fold((string) $dest->getProvincia());

        foreach ($tokens as $token) {
            if (!str_contains($haystack, $this->fold($token))) {
                return false;
            }
        }

        return true;
    }

    private function channelFor(string $kind): string
    {
        return $kind === DestinationCandidate::KIND_PORTAL
            ? AgentTask::TYPE_SUBMIT_REQUEST_PORTAL
            : AgentTask::TYPE_SUBMIT_REQUEST_REG;
    }

    private function channelLabelFor(string $kind): string
    {
        return $kind === DestinationCandidate::KIND_PORTAL
            ? ChannelResolver::BADGE_PORTAL
            : ChannelResolver::BADGE_REG;
    }

    /**
     * @param array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache
     *
     * @return array{id: string, name: string, shortCode: string, deadlineLabel: string}|null
     */
    private function lawArrayForBodyId(string $publicBodyId, array &$lawCache): ?array
    {
        if (array_key_exists($publicBodyId, $lawCache)) {
            return $lawCache[$publicBodyId];
        }

        $body = Uuid::isValid($publicBodyId)
            ? $this->publicBodyRepository->find(Uuid::fromString($publicBodyId))
            : null;

        return $lawCache[$publicBodyId] = $body !== null ? $this->resolveLaw($body) : null;
    }

    /**
     * @param array<string, ?array{id: string, name: string, shortCode: string, deadlineLabel: string}> $lawCache
     *
     * @return array{id: string, name: string, shortCode: string, deadlineLabel: string}|null
     */
    private function lawArrayForBody(PublicBody $body, array &$lawCache): ?array
    {
        $id = $body->getId()->toRfc4122();
        if (array_key_exists($id, $lawCache)) {
            return $lawCache[$id];
        }

        return $lawCache[$id] = $this->resolveLaw($body);
    }

    /**
     * @return array{id: string, name: string, shortCode: string, deadlineLabel: string}|null
     */
    private function resolveLaw(PublicBody $body): ?array
    {
        $law = $this->applicableLawResolver->resolveFor($body);
        if ($law === null) {
            return null;
        }

        return [
            'id' => $law->getId()->toRfc4122(),
            'name' => $law->getName(),
            'shortCode' => $law->getShortCode(),
            'deadlineLabel' => $this->applicableLawResolver->deadlineLabel($law),
        ];
    }

    /** Lowercase + strip common Spanish accents, mirroring the SQL unaccent(lower()). */
    private function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
