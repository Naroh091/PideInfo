<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtnJsonReader
{
    private const DEFAULT_URL = 'https://gist.githubusercontent.com/Naroh091/3fe22565cfd94dfa1ac5bc8e7244b7fe/raw/7b948c2deefd3158e6a8bcc33aca824ce1c8b689/resoluciones_ctn.json';

    private const OUTCOME_MAP = [
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
        'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'estimada parcialmente / inadmitida' => Resolution::OUTCOME_PARTIAL,
        'retrotraer actuaciones' => Resolution::OUTCOME_ROLLBACK,
        'finalizado procedimiento' => Resolution::OUTCOME_ARCHIVED,
        'inadmitida/desestimada' => Resolution::OUTCOME_INADMISSIBLE,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ResolutionData[]
     */
    public function fetchAll(?int $limit = null, ?string $url = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $sourceUrl = $url ?? self::DEFAULT_URL;

        $progress('Fetching JSON data...');
        $response = $this->httpClient->request('GET', $sourceUrl, ['timeout' => 60]);
        $entries = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $progress(sprintf('Loaded %d entries from JSON.', count($entries)));

        $dtos = [];
        $skipped = 0;

        foreach ($entries as $entry) {
            $reference = trim($entry['acuerdo'] ?? '');
            if (!str_starts_with(strtoupper($reference), 'AR')) {
                $skipped++;
                continue;
            }

            $entry = $this->fixColumnShift($entry);
            $dto = $this->buildResolutionData($entry);

            if ($dto !== null) {
                $dtos[] = $dto;
            }

            if ($limit !== null && count($dtos) >= $limit) {
                break;
            }
        }

        if ($skipped > 0) {
            $this->logger->info('Skipped non-AR entries', ['count' => $skipped]);
        }

        usort($dtos, function (ResolutionData $a, ResolutionData $b): int {
            $dateA = $a->resolutionDate ?? new \DateTimeImmutable('@0');
            $dateB = $b->resolutionDate ?? new \DateTimeImmutable('@0');
            return $dateB <=> $dateA;
        });

        $progress(sprintf('Mapped %d resolutions.', count($dtos)));

        return $dtos;
    }

    /**
     * Detect and fix column-shifted entries where fecha contains description text
     * instead of a date. In these cases the columns are shifted left by one position.
     */
    private function fixColumnShift(array $entry): array
    {
        $fecha = trim($entry['fecha'] ?? '');

        if ($fecha !== '' && !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
            $this->logger->info('Column shift detected, attempting recovery', [
                'reference' => $entry['acuerdo'],
                'fecha_value' => mb_substr($fecha, 0, 60),
            ]);

            $realFecha = trim($entry['administracion'] ?? '');
            $realAdmin = trim($entry['motivo'] ?? '');
            $realMotivo = trim($entry['sentido'] ?? '');

            $entry['descripcion'] = $fecha;
            $entry['fecha'] = $realFecha;
            $entry['administracion'] = $realAdmin;
            $entry['motivo'] = $realMotivo;
            $entry['sentido'] = '';
            $entry['_column_shifted'] = true;
        }

        return $entry;
    }

    private function buildResolutionData(array $entry): ?ResolutionData
    {
        $reference = trim($entry['acuerdo'] ?? '');
        $outcome = $this->mapOutcome(trim($entry['sentido'] ?? ''));
        $columnShifted = $entry['_column_shifted'] ?? false;

        if ($outcome === null && !$columnShifted) {
            $this->logger->warning('Skipping CTN resolution without outcome', [
                'reference' => $reference,
            ]);
            return null;
        }

        $resolutionDate = $this->parseDate(trim($entry['fecha'] ?? ''));
        $entryYear = $this->extractYearFromReference($reference);
        $publicBodyName = $this->extractPublicBody($entry['archivo_url'] ?? '');
        $subject = $this->cleanDescription($entry['descripcion'] ?? '');

        $administracion = trim($entry['administracion'] ?? '');
        $motivo = trim($entry['motivo'] ?? '');

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome ?? 'unknown',
            source: Resolution::SOURCE_CTN,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: $subject,
            publicBodyName: $publicBodyName,
            claimReason: $motivo !== '' ? $motivo : null,
            sourceUrl: $entry['archivo_url'] ?? null,
            entityType: $administracion !== '' ? $administracion : null,
            autonomousCommunityName: 'Comunidad Foral de Navarra',
            entryYear: $entryYear,
            sourceMetadata: array_filter([
                'rawOutcome' => trim($entry['sentido'] ?? '') ?: null,
                'motivo' => $motivo !== '' ? $motivo : null,
                'columnShifted' => $columnShifted ?: null,
                'FECHA_RESOLUCION' => $resolutionDate !== null ? 'web' : null,
            ], fn ($v) => $v !== null),
            resolutionDate: $resolutionDate,
            complaintOrganismShortName: 'CTN',
        );
    }

    private function mapOutcome(string $sentido): ?string
    {
        if ($sentido === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($sentido));

        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_contains($normalized, $key)) {
                return $value;
            }
        }

        $this->logger->warning('Unmapped CTN outcome', ['outcome' => $sentido]);

        return mb_strtolower($sentido);
    }

    private function parseDate(string $fecha): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fecha, $m)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(sprintf('%s-%s-%s', $m[3], $m[2], $m[1]));
        } catch (\Exception) {
            return null;
        }
    }

    private function extractYearFromReference(string $reference): ?int
    {
        if (preg_match('/(\d{4})$/', $reference, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractPublicBody(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $decoded = urldecode($url);
        $filename = basename($decoded);

        // New format: "AR 356 2025 sobre R 353 2025 Ayuntamiento de Iguzquiza.pdf"
        if (preg_match('/sobre R \d+ \d+ (.+?)\.pdf$/i', $filename, $m)) {
            return trim($m[1]);
        }

        // Old format: "ar_40_2020_ayuntamiento_de_cortes.pdf"
        if (preg_match('/^ar[._]\d+[._]\d{4}[._](.+?)\.pdf$/i', $filename, $m)) {
            $name = str_replace('_', ' ', trim($m[1]));
            return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return null;
    }

    private function cleanDescription(string $text): ?string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, 500);
    }
}
