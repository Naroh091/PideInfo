<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtpdaCsvReader
{
    private const CSV_URL = 'https://www.ctpdandalucia.es/sites/default/files/views_data_export/vista_buscar_reclamaciones_data_export_1/1775309127/Vista%20Buscar%20Reclamaciones.csv';

    private const OUTCOME_MAP = [
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'archivada' => Resolution::OUTCOME_ARCHIVED,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'declaración terminación proced' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'declaracion terminacion proced' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pérdida sobrevenida del objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'terminación del procedimiento' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmisión a trámite' => Resolution::OUTCOME_INADMISSIBLE,
        'estimación parcial' => Resolution::OUTCOME_PARTIAL,
        'parcialmente estimada' => Resolution::OUTCOME_PARTIAL,
        'retrotraer' => Resolution::OUTCOME_ROLLBACK,
        'inhibición' => Resolution::OUTCOME_INHIBITION,
        'derivación' => Resolution::OUTCOME_REFERRAL,
        'acuerdo' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
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

    /**
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $progress('Downloading CTPDA CSV export (this may take a few minutes)...');

        try {
            $response = $this->httpClient->request('GET', self::CSV_URL, [
                'timeout' => 300,
                'verify_peer' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
            ]);
            $csvContent = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to download CTPDA CSV', ['error' => $e->getMessage()]);

            return [];
        }

        $progress(sprintf('Downloaded %d bytes. Parsing...', strlen($csvContent)));

        $entries = $this->parseCsv($csvContent);
        $progress(sprintf('Parsed %d resolutions from CSV.', count($entries)));

        if ($limit !== null && count($entries) > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        return $entries;
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseCsv(string $csvContent): array
    {
        $entries = [];

        // Strip UTF-8 BOM if present
        $csvContent = ltrim($csvContent, "\xEF\xBB\xBF");

        // Use a memory stream so fgetcsv handles quoted fields with embedded newlines
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        // Read header row (comma-separated)
        $header = fgetcsv($stream, 0, ',');
        if ($header === false || count($header) < 2) {
            $this->logger->warning('CTPDA CSV has no valid header');

            fclose($stream);
            return [];
        }

        $columnMap = array_flip(array_map('trim', $header));

        // Normalize header keys that may have encoding variations
        if (!isset($columnMap['Nº'])) {
            $columnMap['Nº'] = 0; // First column is always the reference number
        }
        if (!isset($columnMap['Ámbito'])) {
            // Try partial match for Ámbito (encoding issues with accent)
            foreach ($columnMap as $key => $idx) {
                if (str_contains($key, 'mbito') || str_contains($key, 'Ambito')) {
                    $columnMap['Ámbito'] = $idx;
                    break;
                }
            }
        }

        // Validate expected columns exist
        $requiredColumns = ['Nº', 'Fecha', 'Resumen', 'Sentido', 'Organismo', 'Url'];
        foreach ($requiredColumns as $col) {
            if (!isset($columnMap[$col])) {
                $this->logger->error('CTPDA CSV missing required column', ['column' => $col, 'available' => array_keys($columnMap)]);

                fclose($stream);
                return [];
            }
        }

        while (($row = fgetcsv($stream, 0, ',')) !== false) {
            if (count($row) < count($requiredColumns)) {
                continue;
            }

            $dto = $this->parseRow($row, $columnMap);
            if ($dto !== null) {
                $entries[] = $dto;
            }
        }

        fclose($stream);

        return $entries;
    }

    /**
     * @param array<int, string> $row
     * @param array<string, int> $columnMap
     */
    private function parseRow(array $row, array $columnMap): ?ResolutionData
    {
        $reference = trim($row[$columnMap['Nº']] ?? '');
        if ($reference === '') {
            return null;
        }

        // Extract year from reference (e.g. "474/2026" → 2026)
        $entryYear = null;
        if (preg_match('/\/(\d{4})$/', $reference, $m)) {
            $entryYear = (int) $m[1];
        }

        // Parse resolution date (DD/MM/YYYY)
        $resolutionDate = null;
        $fechaRaw = trim($row[$columnMap['Fecha']] ?? '');
        if ($fechaRaw !== '') {
            $resolutionDate = $this->parseDate($fechaRaw);
        }

        // Map outcome
        $sentido = trim($row[$columnMap['Sentido']] ?? '');
        $outcome = $this->mapOutcome($sentido);

        // Map scope from Ámbito (column may be absent if header encoding failed)
        $ambitoIdx = $columnMap['Ámbito'] ?? null;
        $ambito = $ambitoIdx !== null ? trim($row[$ambitoIdx] ?? '') : '';
        $scope = $this->mapScope($ambito);

        // Public body name
        $publicBodyName = trim($row[$columnMap['Organismo']] ?? '') ?: null;

        // Summary
        $summary = trim($row[$columnMap['Resumen']] ?? '') ?: null;

        // Source URL
        $sourceUrl = trim($row[$columnMap['Url']] ?? '') ?: null;

        // Build sourceMetadata from extra columns
        $sourceMetadata = [];
        $provincia = trim($row[$columnMap['Provincia'] ?? -1] ?? '');
        if ($provincia !== '') {
            $sourceMetadata['provincia'] = $provincia;
        }
        $indicador = trim($row[$columnMap['Indicador'] ?? -1] ?? '');
        if ($indicador !== '') {
            $sourceMetadata['indicador'] = $indicador;
        }
        $ley = trim($row[$columnMap['Ley'] ?? -1] ?? '');
        if ($ley !== '') {
            $sourceMetadata['ley'] = $ley;
        }
        $art = trim($row[$columnMap['Art'] ?? -1] ?? '');
        if ($art !== '') {
            $sourceMetadata['art'] = $art;
        }
        $parrafo = trim($row[$columnMap['Párrafo'] ?? -1] ?? '');
        if ($parrafo !== '') {
            $sourceMetadata['parrafo'] = $parrafo;
        }

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTPDA,
            scope: $scope,
            publicBodyName: $publicBodyName,
            sourceUrl: $sourceUrl,
            autonomousCommunityName: 'Andalucía',
            entryYear: $entryYear,
            sourceMetadata: !empty($sourceMetadata) ? $sourceMetadata : null,
            resolutionDate: $resolutionDate,
            summary: $summary,
            complaintOrganismShortName: 'CTPDA',
        );
    }

    public function mapOutcome(string $sentido): string
    {
        if (trim($sentido) === '') {
            return 'pending';
        }

        $normalized = mb_strtolower(trim($sentido));

        // Exact match
        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Substring match (longest keys first)
        $keys = array_keys(self::OUTCOME_MAP);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($keys as $key) {
            if (str_contains($normalized, $key)) {
                return self::OUTCOME_MAP[$key];
            }
        }

        $this->logger->warning('Unmapped CTPDA outcome', ['outcome' => $sentido]);

        return $normalized;
    }

    private function mapScope(string $ambito): string
    {
        $normalized = mb_strtolower(trim($ambito));

        if (str_contains($normalized, 'local')) {
            return Resolution::SCOPE_LOCAL;
        }

        return Resolution::SCOPE_AUTONOMOUS;
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        // Try DD/MM/YYYY format
        $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $raw);
        if ($parsed !== false) {
            return $parsed->setTime(0, 0);
        }

        return null;
    }

    /**
     * Extract all valid "DD de MONTH de YYYY" dates from text.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public static function extractSpanishDates(string $text): array
    {
        $dates = [];

        if (!preg_match_all('/(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $text, $matches, PREG_SET_ORDER)) {
            return $dates;
        }

        foreach ($matches as $m) {
            $month = self::SPANISH_MONTHS[mb_strtolower($m[2])] ?? null;
            if ($month === null) {
                continue;
            }

            try {
                $dates[] = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1])))->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        return $dates;
    }

    /**
     * Parse claim date and public body from extracted PDF text.
     *
     * @return array{publicBodyName: ?string, claimDate: ?\DateTimeImmutable}
     */
    public static function parseMetadataFromText(string $fullText): array
    {
        $result = [
            'publicBodyName' => null,
            'claimDate' => null,
        ];

        // Extract "Entidad reclamada" from header block
        if (preg_match('/Entidad reclamada\s+(.+?)(?:\n\s*(?:Art[ií]culos|Normativa|$))/ius', $fullText, $m)) {
            $publicBody = preg_replace('/\s+/', ' ', trim($m[1]));
            $result['publicBodyName'] = rtrim($publicBody, '.');
        }

        // Extract claim date from PRIMERO section
        $antecedentesText = $fullText;
        $fjPos = mb_stripos($fullText, 'FUNDAMENTOS JURÍDICOS');
        if ($fjPos === false) {
            $fjPos = mb_stripos($fullText, 'FUNDAMENTOS JUR');
        }
        if ($fjPos !== false) {
            $antecedentesText = mb_substr($fullText, 0, $fjPos);
        }

        // Find the PRIMERO paragraph
        $primeroPos = mb_stripos($antecedentesText, 'Primero.');
        if ($primeroPos === false) {
            $primeroPos = mb_stripos($antecedentesText, 'Primero,');
        }
        if ($primeroPos !== false) {
            $primeroText = mb_substr($antecedentesText, $primeroPos);
            $segundoPos = mb_stripos($primeroText, 'Segundo');
            if ($segundoPos !== false) {
                $primeroText = mb_substr($primeroText, 0, $segundoPos);
            }

            // Find "escrito presentado el DD de month de YYYY"
            $dates = self::extractSpanishDates($primeroText);
            if (!empty($dates)) {
                $result['claimDate'] = $dates[0];
            }
        }

        return $result;
    }
}
