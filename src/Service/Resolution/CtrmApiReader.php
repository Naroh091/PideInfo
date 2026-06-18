<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads resolutions from the CTRM (Comisionado de Transparencia de la Región de
 * Murcia) portal API — a Liferay headless collection endpoint that returns the
 * resolutions paginated and ordered DATE DESC.
 *
 * Note: the PDFs frequently lack a text layer on some/all pages; that is handled
 * downstream by the vision-OCR fallback in the processing pipeline
 * ({@see \App\Service\Document\PdfOcrTranscriber}), not here.
 */
class CtrmApiReader
{
    private const BASE_URL = 'https://comisionadotransparencia.carm.es';
    private const API_URL = self::BASE_URL . '/o/c/reclamacions/scopes/32972';
    private const SORT = 'anho:desc,referencia:desc,id:asc';
    private const PAGE_SIZE = 100;

    /**
     * Outcome keyword fragments specific to CTRM's `palabraClave` field, checked
     * (as substrings, accent-insensitive) when the generic OUTCOME_MAP misses.
     */
    private const OUTCOME_FRAGMENTS = [
        'inadmi' => Resolution::OUTCOME_INADMISSIBLE,
        'remision al organo competente' => Resolution::OUTCOME_REFERRAL,
        'remision' => Resolution::OUTCOME_REFERRAL,
        'derivaci' => Resolution::OUTCOME_REFERRAL,
        'desestim' => Resolution::OUTCOME_UNFAVORABLE,
        'estimaci parcial' => Resolution::OUTCOME_PARTIAL,
        'estimaci' => Resolution::OUTCOME_FAVORABLE,
        'estima parcial' => Resolution::OUTCOME_PARTIAL,
        'estimat' => Resolution::OUTCOME_FAVORABLE,
        'parcial' => Resolution::OUTCOME_PARTIAL,
        'archiv' => Resolution::OUTCOME_ARCHIVED,
        'desist' => Resolution::OUTCOME_WITHDRAWAL,
        'perdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'perdida sobrevenida' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'inhib' => Resolution::OUTCOME_INHIBITION,
        'mediaci' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
        'retroac' => Resolution::OUTCOME_ROLLBACK,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResolutionData[]
     */
    public function fetchAll(?int $limit = null): array
    {
        $results = [];
        $page = 1;

        do {
            $payload = $this->fetchPage($page);
            $items = $payload['items'] ?? [];

            foreach ($items as $item) {
                $dto = $this->parseRecord($item);
                if ($dto !== null) {
                    $results[$dto->referenceNumber] = $dto;
                }

                if ($limit !== null && count($results) >= $limit) {
                    return array_slice($results, 0, $limit, true);
                }
            }

            $lastPage = (int) ($payload['lastPage'] ?? $page);
            $page++;
        } while ($items !== [] && $page <= $lastPage);

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $page): array
    {
        // Build the URL by hand so the `sort` operators (`:` / `,`) are preserved
        // verbatim as the API expects, rather than being percent-encoded.
        $url = sprintf('%s?page=%d&pageSize=%d&sort=%s', self::API_URL, $page, self::PAGE_SIZE, self::SORT);

        $this->logger->info('Fetching CTRM API', ['page' => $page, 'pageSize' => self::PAGE_SIZE]);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 120,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $record
     */
    private function parseRecord(array $record): ?ResolutionData
    {
        $reference = isset($record['referencia']) ? trim((string) $record['referencia']) : '';
        if ($reference === '') {
            return null;
        }

        // `palabraClave` is a free-form field the CTRM staff fill inconsistently:
        // sometimes it holds the sentido/outcome ("ESTIMATORIA", "INADMISIÓN - …"),
        // sometimes a thematic classification ("Ámbito subjetivo: … Ámbito material: …").
        // The API exposes NO dedicated outcome field, so the authoritative outcome is
        // extracted later from the PDF by ResolutionAnalyzer (which overwrites this).
        $palabraClave = isset($record['palabraClave']) ? trim((string) $record['palabraClave']) : '';

        $entryYear = isset($record['anho']) ? (int) $record['anho'] : null;

        $sourceUrl = null;
        $href = $record['documentoAdjunto']['link']['href'] ?? null;
        if (is_string($href) && $href !== '') {
            $sourceUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
        }

        // Outcome: map only when palabraClave is clearly a sentido. Otherwise leave it
        // empty (the column is NOT NULL, so '' rather than null) so the AI analysis can
        // set the real value from the PDF without us fabricating a wrong outcome.
        $outcome = '';
        $topics = null;
        $keywords = null;
        $ambitoMeta = [];

        if ($palabraClave !== '') {
            if (mb_stripos($palabraClave, 'ámbito') !== false) {
                // Thematic form: keep the ámbito as topics/metadata, leave outcome empty.
                [$subjetivo, $material] = $this->parseAmbito($palabraClave);
                if ($subjetivo !== null) {
                    $ambitoMeta['ambitoSubjetivo'] = $subjetivo;
                }
                if ($material !== null) {
                    $ambitoMeta['ambitoMaterial'] = $material;
                    $parts = array_values(array_filter(array_map('trim', preg_split('/[.;,]/', $material) ?: [])));
                    $topics = $parts !== [] ? $parts : null;
                }
            } else {
                $mapped = $this->mapOutcome($palabraClave);
                if ($mapped !== null) {
                    $outcome = $mapped;
                } else {
                    // Unrecognized free text: preserve it as keywords (raw also kept in metadata).
                    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[-\/;]\s*/', $palabraClave) ?: [])));
                    $keywords = $parts !== [] ? $parts : null;
                }
            }
        }

        $sourceMetadata = array_filter([
            'id' => $record['id'] ?? null,
            'externalReferenceCode' => $record['externalReferenceCode'] ?? null,
            'status' => $record['status']['label_i18n'] ?? ($record['status']['label'] ?? null),
            'organismo' => $record['organismo'] ?? null,
            'documentName' => $record['documentoAdjunto']['name'] ?? null,
            'dateCreated' => $record['dateCreated'] ?? null,
            'palabraClave' => $palabraClave !== '' ? $palabraClave : null,
            ...$ambitoMeta,
        ], fn ($v) => $v !== null && $v !== '');

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTRM,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: isset($record['asuntoDeLaReclamacion']) ? trim((string) $record['asuntoDeLaReclamacion']) : null,
            sourceUrl: $sourceUrl,
            autonomousCommunityName: 'Región de Murcia',
            entryYear: $entryYear,
            topics: $topics,
            keywords: $keywords,
            sourceMetadata: !empty($sourceMetadata) ? $sourceMetadata : null,
            year: $entryYear,
            complaintOrganismShortName: 'CTRM',
        );
    }

    /**
     * Parse the "Ámbito subjetivo: … Ámbito material: …" form of palabraClave.
     *
     * @return array{0: ?string, 1: ?string} [ámbito subjetivo, ámbito material]
     */
    private function parseAmbito(string $text): array
    {
        $subjetivo = null;
        $material = null;

        if (preg_match('/ámbito\s+subjetivo\s*:\s*(.+?)(?=\s*ámbito\s+material\s*:|$)/iu', $text, $m)) {
            $subjetivo = trim($m[1]) ?: null;
        }
        if (preg_match('/ámbito\s+material\s*:\s*(.+)$/iu', $text, $m)) {
            $material = trim($m[1]) ?: null;
        }

        return [$subjetivo, $material];
    }

    /**
     * Map a palabraClave sentido to a Resolution::OUTCOME_* code, or null when it
     * does not cleanly correspond to a known outcome (never returns raw text).
     */
    private function mapOutcome(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));

        // Generic map (exact, then prefix) using the shared Excel/GAIP table.
        if (isset(ExcelResolutionReader::OUTCOME_MAP[$normalized])) {
            return ExcelResolutionReader::OUTCOME_MAP[$normalized];
        }
        foreach (ExcelResolutionReader::OUTCOME_MAP as $key => $value) {
            if (str_starts_with($normalized, $key)) {
                return $value;
            }
        }

        // CTRM-specific substring fragments (accent-insensitive).
        $ascii = $this->stripAccents($normalized);
        foreach (self::OUTCOME_FRAGMENTS as $fragment => $value) {
            if (str_contains($ascii, $fragment)) {
                return $value;
            }
        }

        return null;
    }

    private function stripAccents(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
