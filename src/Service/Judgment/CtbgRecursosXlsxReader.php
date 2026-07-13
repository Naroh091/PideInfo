<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\DTO\JudgmentData;
use App\Entity\Judgment;
use App\Service\Ingestion\DocumentFetcher;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsxDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;

/**
 * Reads the CTBG "Recursos contra resoluciones" XLSX: one row per RECURSO, up to three
 * judgments per row (first instance, appeal before the Audiencia Nacional, cassation before
 * the Tribunal Supremo), each cell hyperlinked to its sentencia.
 *
 * Everything here was verified against the LIVE file (435 recursos, 444 sentencia cells):
 *  - Columns are resolved by their literal header text, never by position, and a missing
 *    header ABORTS the import: the CTBG reshuffles this file, and reading the wrong column
 *    silently would poison every judgment at once.
 *  - The reference is court + number ("JCCA9/60/2016"), because the bare sentencia number is
 *    not unique — the live file has two 60/2016 from different juzgados.
 *  - Dates are read from the raw Excel serial: the formatted value renders as US m/d/Y.
 *  - Links to poderjudicial.es (CENDOJ, 65 of 444) serve a search shell to plain HTTP; they
 *    are flagged needsBrowser and their PDFs are a later, Camofox-driven phase.
 */
final class CtbgRecursosXlsxReader implements JudgmentReaderInterface
{
    public const XLSX_URL = 'https://consejodetransparencia.es/content/dam/ctransparencia/portal-ctbg/reclamaciones/recursos-y-jurisprudencia/recursos-%C3%A1mbito-estatal/RecursosjudicialesAE.xlsx';

    /**
     * logical name => prefix of the literal header (row 1). Prefixes, because the full
     * headers are sentences ("Sentencias en primera instancia: Juzgados…").
     */
    private const HEADERS = [
        'recurso' => 'Nº',
        'year' => 'Año',
        'refs' => 'Resolución recurrida',
        'subject' => 'Asunto',
        'appellant' => 'Demandante',
        'appellantType' => 'Tipo de demandante',
        'observations' => 'Observaciones y des',   // "desestimientos" (sic) — prefix survives the typo being fixed
        'juzgado' => 'Juzgado',
        'firstInstance' => 'Sentencias en primera instancia',
        'appeal' => 'Sentencias en segunda instancia',
        'cassation' => 'Casación',
        'finality' => 'Firmeza',
        'finalityDate' => 'Fecha de la firmeza',
        'representation' => 'Representación',
    ];

    public function __construct(
        private readonly DocumentFetcher $fetcher,
        private readonly LoggerInterface $logger,
        private ?string $localFile = null,
    ) {
    }

    /** For tests and for `--file`: read a local copy instead of downloading. */
    public function withLocalFile(string $path): self
    {
        $clone = clone $this;
        $clone->localFile = $path;

        return $clone;
    }

    public function getSource(): string
    {
        return Judgment::SOURCE_CTBG_RECURSOS;
    }

    public function fetchAll(?int $limit = null): array
    {
        $path = $this->localFile ?? $this->download();
        $sheet = IOFactory::load($path)->getSheet(0);

        $columns = $this->resolveColumns($sheet);

        $judgments = [];
        $maxRow = $sheet->getHighestRow();
        $rows = 0;

        for ($row = 2; $row <= $maxRow; $row++) {
            if ($limit !== null && $rows >= $limit) {
                break;
            }

            $fromRow = $this->readRow($sheet, $columns, $row);
            if ($fromRow === []) {
                continue;
            }

            ++$rows;
            $judgments = array_merge($judgments, $fromRow);
        }

        $this->logger->info('CTBG recursos XLSX read', [
            'rows' => $rows,
            'judgments' => count($judgments),
        ]);

        return $judgments;
    }

    /**
     * @param array<string, string> $columns logical name => column letter
     *
     * @return list<JudgmentData> instancia → apelación → casación, chained
     */
    private function readRow(Worksheet $sheet, array $columns, int $row): array
    {
        $cell = fn (string $name) => $sheet->getCell($columns[$name] . $row);
        $text = fn (string $name): string => trim((string) $cell($name)->getValue());

        $recursoNumber = $text('recurso');
        $year = $text('year');

        if ($recursoNumber === '' && $text('refs') === '') {
            return [];   // trailing empty row
        }

        $parsedRefs = ChallengedResolutionRefParser::parse($text('refs'));

        $isFinal = str_contains(mb_strtolower($text('finality')), 'firme');
        $finalDate = $this->readDate($cell('finalityDate')->getValue());

        $shared = [
            'refs' => $parsedRefs['refs'],
            'unparsed' => $parsedRefs['unparsed'],
            'subject' => $text('subject') !== '' ? $text('subject') : null,
            'appellant' => $text('appellant') !== '' ? $text('appellant') : null,
            'appellantType' => $text('appellantType') !== '' ? $text('appellantType') : null,
            'representation' => $text('representation') !== '' ? $text('representation') : null,
            'metadata' => array_filter([
                'recursoNumber' => $recursoNumber,
                'recursoYear' => $year,
                'detailUrl' => $this->hyperlink($cell('recurso')),
                'observations' => $text('observations') !== '' ? $text('observations') : null,
            ]),
        ];

        // Instancia: the juzgado column disambiguates the two 60/2016 of the live file.
        $juzgado = preg_match('/(\d+)/', $text('juzgado'), $m) ? (int) $m[1] : null;

        $chain = array_values(array_filter([
            $this->stage($cell('firstInstance'), Judgment::COURT_JCCA, $juzgado, Judgment::INSTANCE_FIRST, sprintf('JCCA%s', $juzgado ?? '?')),
            $this->stage($cell('appeal'), Judgment::COURT_AN, null, Judgment::INSTANCE_APPEAL, 'AN'),
            $this->stage($cell('cassation'), Judgment::COURT_TS, null, Judgment::INSTANCE_CASSATION, 'TS'),
        ]));

        $judgments = [];
        $previousRef = null;
        $last = count($chain) - 1;

        foreach ($chain as $i => $stage) {
            // Only the LAST ruling of the chain carries the firmeza: a cassated
            // first-instance judgment is history, not the law of the case.
            //
            // And a DECIDED cassation is final by nature — the Supreme Court is the end of
            // the road — even when the CTBG has not yet filled its firmeza column. Verified
            // on the BOSCO case: STS of 11/9/2025 existed, firmeza cell still empty.
            $isCassation = $stage['instance'] === Judgment::INSTANCE_CASSATION;

            $judgments[] = new JudgmentData(
                referenceNumber: $stage['reference'],
                source: Judgment::SOURCE_CTBG_RECURSOS,
                court: $stage['court'],
                courtNumber: $stage['courtNumber'],
                instance: $stage['instance'],
                judgmentNumber: $stage['number'],
                challengedResolutionRefs: $shared['refs'],
                unparsedRefs: $shared['unparsed'],
                subject: $shared['subject'],
                appellant: $shared['appellant'],
                appellantType: $shared['appellantType'],
                representation: $shared['representation'],
                sourceUrl: $stage['url'],
                needsBrowser: $stage['needsBrowser'],
                isFinal: ($isFinal && $i === $last) || $isCassation,
                finalDate: $i === $last ? $finalDate : null,
                reviewedReferenceNumber: $previousRef,
                sourceMetadata: $shared['metadata'],
            );

            $previousRef = $stage['reference'];
        }

        return $judgments;
    }

    /**
     * One stage of the recurso chain, when its cell actually holds a sentencia number.
     *
     * @return array{reference: string, court: string, courtNumber: ?int, instance: string, number: string, url: ?string, needsBrowser: bool}|null
     */
    private function stage(
        \PhpOffice\PhpSpreadsheet\Cell\Cell $cell,
        string $court,
        ?int $courtNumber,
        string $instance,
        string $refPrefix,
    ): ?array {
        $value = trim((string) $cell->getValue());

        // The cassation column mixes admission orders ("Auto de admisión del recurso 75/2017")
        // with judgment numbers; only a leading number/year is a sentencia.
        if ($value === '' || !preg_match('/^(\d+)\s*\/\s*(\d{4})/', $value, $m)) {
            return null;
        }

        $url = $this->hyperlink($cell);
        $number = $m[1] . '/' . $m[2];

        return [
            'reference' => $refPrefix . '/' . $number,
            'court' => $court,
            'courtNumber' => $courtNumber,
            'instance' => $instance,
            'number' => $number,
            'url' => $url,
            'needsBrowser' => $url !== null && str_contains($url, 'poderjudicial.es'),
        ];
    }

    /**
     * @param array<string, string> $columns
     */
    private function resolveColumns(Worksheet $sheet): array
    {
        $columns = [];

        foreach ($sheet->getRowIterator(1, 1) as $row) {
            $it = $row->getCellIterator();
            $it->setIterateOnlyExistingCells(true);

            foreach ($it as $cell) {
                $header = trim((string) $cell->getValue());
                if ($header === '') {
                    continue;
                }

                foreach (self::HEADERS as $name => $prefix) {
                    if (!isset($columns[$name]) && str_starts_with($header, $prefix)) {
                        $columns[$name] = preg_replace('/\d+$/', '', $cell->getCoordinate()) ?? '';
                    }
                }
            }
        }

        $missing = array_diff_key(self::HEADERS, $columns);
        if ($missing !== []) {
            // Loudly, not silently: the CTBG reshuffles this file, and importing from the
            // wrong column would poison every judgment at once.
            throw new \RuntimeException(sprintf(
                'El XLSX de recursos del CTBG ha cambiado de formato: faltan las cabeceras [%s]. Revisa CtbgRecursosXlsxReader::HEADERS.',
                implode(', ', array_keys($missing)),
            ));
        }

        return $columns;
    }

    private function hyperlink(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?string
    {
        if (!$cell->hasHyperlink()) {
            return null;
        }

        $url = trim($cell->getHyperlink()->getUrl());

        return $url !== '' ? $url : null;
    }

    private function readDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_numeric($raw)) {
            return null;   // the formatted string renders as US m/d/Y — never parse that
        }

        return \DateTimeImmutable::createFromMutable(XlsxDate::excelToDateTimeObject((float) $raw));
    }

    private function download(): string
    {
        $content = $this->fetcher->fetch(self::XLSX_URL);

        if ($content === null || !str_starts_with($content, "PK")) {
            throw new \RuntimeException(
                'No se ha podido descargar el XLSX de recursos del CTBG (o la respuesta no es un XLSX). '
                . 'Recuerda: consejodetransparencia.es devuelve 404 sin User-Agent de navegador.',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'ctbg_recursos_') . '.xlsx';
        file_put_contents($path, $content);

        return $path;
    }
}
