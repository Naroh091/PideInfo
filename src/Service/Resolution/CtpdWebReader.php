<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtpdWebReader
{
    // Legacy source (asambleamadrid.es)
    private const BASE_URL = 'https://ctyp.asambleamadrid.es';
    private const LISTING_URL = '/actividad-y-evaluacion/resoluciones';

    // New source (comunidad.madrid)
    private const BASE_URL_NEW = 'https://www.comunidad.madrid';
    private const LISTING_URL_NEW = '/servicios/consejo-transparencia-proteccion-datos/derecho-acceso-informacion-publica';

    private const ACCORDION_OUTCOME_MAP = [
        '493737' => Resolution::OUTCOME_FAVORABLE,
        '493738' => Resolution::OUTCOME_PARTIAL,
        '493739' => Resolution::OUTCOME_UNFAVORABLE,
        '493740' => Resolution::OUTCOME_WITHDRAWAL,
        '493741' => Resolution::OUTCOME_INADMISSIBLE,
        '493742' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
    ];

    private const OUTCOME_MAP = [
        'estimación' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'estimar' => Resolution::OUTCOME_FAVORABLE,
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'desestimación' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimar' => Resolution::OUTCOME_UNFAVORABLE,
        'estimación parcial' => Resolution::OUTCOME_PARTIAL,
        'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
        'parcialmente estimada' => Resolution::OUTCOME_PARTIAL,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmisión a trámite' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
        'terminación del procedimiento' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pérdida sobrevenida del objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pérdida sobrevenida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'archivada' => Resolution::OUTCOME_ARCHIVED,
    ];

    private const SPANISH_MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─── New source (comunidad.madrid) ────────────────────────────────

    /**
     * Fetch all resolutions from the comunidad.madrid accordion page.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $progress('Fetching CTPD listing page from comunidad.madrid...');

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL_NEW . self::LISTING_URL_NEW, [
                'timeout' => 60,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
            ]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch CTPD listing page', ['error' => $e->getMessage()]);

            return [];
        }

        $entries = $this->parseListingPage($html);
        $progress(sprintf('Found %d resolutions in listing.', count($entries)));

        if ($limit !== null && count($entries) > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseListingPage(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        foreach (self::ACCORDION_OUTCOME_MAP as $accordionId => $outcome) {
            $panel = $crawler->filter('#panel-' . $accordionId);
            if ($panel->count() === 0) {
                $this->logger->warning('Accordion panel not found', ['id' => $accordionId]);
                continue;
            }

            $panel->filter('a[href$=".pdf"]')->each(function (Crawler $link) use (&$entries, $outcome) {
                $href = $link->attr('href');
                $text = trim($link->text());

                $dto = $this->parseLinkEntry($href, $text, $outcome);
                if ($dto !== null) {
                    $entries[] = $dto;
                }
            });
        }

        return $entries;
    }

    private function parseLinkEntry(string $href, string $text, string $outcome): ?ResolutionData
    {
        // Parse reference from link text: "Resolución 001_2025 CTPD" or "Resolución RDACTPCM 033_2024"
        // Handles: underscore, space, non-breaking space as separators; RDACPTCM typo on the portal
        if (!preg_match('/Resoluci[oó]n\s+(?:(RDACTPCM|RDACPTCM)\s+)?(\d{3})[_\s\x{A0}](\d{4})/iu', $text, $m)) {
            $this->logger->warning('Could not parse CTPD link text', ['text' => $text, 'href' => $href]);

            return null;
        }

        $isRdactpcm = !empty($m[1]);
        $number = $m[2];
        $year = (int) $m[3];
        $reference = $isRdactpcm
            ? sprintf('RDACTPCM %s/%d', $number, $year)
            : sprintf('%s/%d', $number, $year);

        // Build full URL
        $url = $href;
        if (!str_starts_with($url, 'http')) {
            $url = self::BASE_URL_NEW . (str_starts_with($url, '/') ? '' : '/') . $url;
        }

        // Detect anomalies
        $errors = [];

        if (!str_contains($href, '/sites/default/files/')) {
            $errors[] = 'URL missing /sites/default/files/ prefix';
        }

        // Check filename/text number mismatch
        $filenameNumber = null;
        if (preg_match('/resolucion_(?:rdactpcm_)?(\d{3})_/', $href, $fm)) {
            $filenameNumber = $fm[1];
        }
        if ($filenameNumber !== null && $filenameNumber !== $number) {
            $errors[] = sprintf('Filename number %s does not match link text number %s', $filenameNumber, $number);
        }

        $sourceMetadata = !empty($errors) ? ['errors' => $errors] : null;

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTPD,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: 'Solicitud de acceso a información pública',
            sourceUrl: $url,
            autonomousCommunityName: 'Comunidad de Madrid',
            entryYear: $year,
            sourceMetadata: $sourceMetadata,
            complaintOrganismShortName: 'CTPD',
        );
    }

    // ─── Legacy source (asambleamadrid.es) ────────────────────────────

    /**
     * Fetch all resolutions from the legacy asambleamadrid.es listing page.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntriesLegacy(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $progress('Fetching CTPD listing page from asambleamadrid.es (legacy)...');

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . self::LISTING_URL, ['timeout' => 30]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch CTPD legacy listing page', ['error' => $e->getMessage()]);

            return [];
        }

        $entries = $this->parseListingPageLegacy($html);
        $progress(sprintf('Found %d resolutions in listing.', count($entries)));

        if ($limit !== null && count($entries) > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseListingPageLegacy(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        $crawler->filter('a[href*="/documents/"]')->each(function (Crawler $link) use (&$entries) {
            $href = $link->attr('href');
            $text = trim($link->text());

            if (!preg_match('/Resoluci[oó]n\s+(RDA\d+\/\d{4})/iu', $text, $refMatch)) {
                return;
            }

            $reference = $refMatch[1];

            $url = $href;
            if (!str_starts_with($url, 'http')) {
                $url = self::BASE_URL . $url;
            }

            $entryYear = null;
            if (preg_match('/\/(\d{4})$/', $reference, $yearMatch)) {
                $entryYear = (int) $yearMatch[1];
            }

            $entries[] = new ResolutionData(
                referenceNumber: $reference,
                outcome: 'pending',
                source: Resolution::SOURCE_CTPD,
                scope: Resolution::SCOPE_AUTONOMOUS,
                sourceUrl: $url,
                autonomousCommunityName: 'Comunidad de Madrid',
                entryYear: $entryYear,
                complaintOrganismShortName: 'CTPD',
            );
        });

        return $entries;
    }

    // ─── Shared: PDF metadata extraction ──────────────────────────────

    /**
     * Parse the structured metadata block from extracted PDF text.
     *
     * @return array{publicBodyName: ?string, subject: ?string, outcomeText: ?string, claimDate: ?\DateTimeImmutable}
     */
    public static function parseMetadataFromText(string $fullText): array
    {
        $result = [
            'publicBodyName' => null,
            'subject' => null,
            'outcomeText' => null,
            'claimDate' => null,
        ];

        // Extract "Administración reclamada: ..."
        if (preg_match('/Administraci[oó]n reclamada:\s*(.+?)(?:\n|$)/iu', $fullText, $m)) {
            $result['publicBodyName'] = trim(rtrim($m[1], '.'));
        }

        // Extract "Información reclamada: ..." (may span multiple lines until next labeled field)
        if (preg_match('/Informaci[oó]n reclamada:\s*(.+?)(?=\n\s*(?:Sentido de la resoluci[oó]n|Reclamante|ANTECEDENTES))/ius', $fullText, $m)) {
            $subject = preg_replace('/\s+/', ' ', trim($m[1]));
            $result['subject'] = rtrim($subject, '.');
        }

        // Extract "Sentido de la resolución: ..."
        if (preg_match('/Sentido de la resoluci[oó]n:\s*(.+?)(?:\n|$)/iu', $fullText, $m)) {
            $result['outcomeText'] = trim(rtrim($m[1], '.'));
        }

        // Extract claim date from PRIMERO paragraph in ANTECEDENTES (before FUNDAMENTOS JURÍDICOS)
        $antecedentesText = $fullText;
        $fjPos = mb_stripos($fullText, 'FUNDAMENTOS JURÍDICOS');
        if ($fjPos === false) {
            $fjPos = mb_stripos($fullText, 'FUNDAMENTOS JUR');
        }
        if ($fjPos !== false) {
            $antecedentesText = mb_substr($fullText, 0, $fjPos);
        }

        // Find the PRIMERO paragraph
        $primeroPos = mb_stripos($antecedentesText, 'PRIMERO.');
        if ($primeroPos === false) {
            $primeroPos = mb_stripos($antecedentesText, 'PRIMERO,');
        }
        if ($primeroPos !== false) {
            // Get text from PRIMERO until SEGUNDO (or end of antecedentes)
            $primeroText = mb_substr($antecedentesText, $primeroPos);
            $segundoPos = mb_stripos($primeroText, 'SEGUNDO');
            if ($segundoPos !== false) {
                $primeroText = mb_substr($primeroText, 0, $segundoPos);
            }

            // Find all dates matching "DD de MONTH de YYYY" with valid Spanish months
            $dates = self::extractSpanishDates($primeroText);

            // If two or more dates found, use the second (actual claim filing date)
            // If one, use it directly
            if (count($dates) >= 2) {
                $result['claimDate'] = $dates[1];
            } elseif (count($dates) === 1) {
                $result['claimDate'] = $dates[0];
            }
        }

        return $result;
    }

    /**
     * Map the "Sentido de la resolución" text to a Resolution outcome constant.
     */
    public function mapOutcome(?string $outcomeStr): string
    {
        if ($outcomeStr === null || trim($outcomeStr) === '') {
            return 'pending';
        }

        $normalized = mb_strtolower(trim($outcomeStr));

        // Exact match
        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Check if any key is contained in the text (for compound outcomes like
        // "Terminación del procedimiento. Pérdida de objeto.")
        // Try longer keys first for better matching
        $keys = array_keys(self::OUTCOME_MAP);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($keys as $key) {
            if (str_contains($normalized, $key)) {
                return self::OUTCOME_MAP[$key];
            }
        }

        $this->logger->warning('Unmapped CTPD outcome', ['outcome' => $outcomeStr]);

        return $normalized;
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Extract all valid "DD de MONTH de YYYY" dates from text.
     *
     * @return array<int, \DateTimeImmutable>
     */
    private static function extractSpanishDates(string $text): array
    {
        $dates = [];

        if (!preg_match_all('/(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $text, $matches, PREG_SET_ORDER)) {
            return $dates;
        }

        foreach ($matches as $m) {
            $month = self::SPANISH_MONTHS[mb_strtolower($m[2])] ?? null;
            if ($month === null) {
                // Not a valid month name — filters out law references
                continue;
            }

            try {
                $dates[] = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1])))->setTime(0, 0);
            } catch (\Exception) {
                // Invalid date, skip
            }
        }

        return $dates;
    }

}
