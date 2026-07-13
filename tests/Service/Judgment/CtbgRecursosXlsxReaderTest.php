<?php

declare(strict_types=1);

namespace App\Tests\Service\Judgment;

use App\Entity\Judgment;
use App\Service\Ingestion\DocumentFetcher;
use App\Service\Judgment\CtbgRecursosXlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The fixture XLSX is generated here, cell by cell, mirroring the LIVE file's structure
 * (headers, hyperlinks, US-rendered dates). No binary fixture to rot in the repo.
 */
final class CtbgRecursosXlsxReaderTest extends TestCase
{
    private static string $fixture;

    public static function setUpBeforeClass(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recursos_AGE');

        $sheet->fromArray([
            'Nº', 'Año', 'Resolución recurrida', 'Asunto', 'Demandante', 'Tipo de demandante (1)',
            'Observaciones y desestimientos', 'Juzgado CCAA - Audiencia Nacional',
            'Sentencias en primera instancia: Juzgados Centrales', 'Observaciones de la apelación',
            'Sentencias en segunda instancia: sala de lo contencioso de la AN', 'Observaciones',
            'Recurso de casación', 'Casación: sentencias del Tribunal Supremo',
            'Firmeza', 'Fecha de la firmeza', 'Representación',
        ], null, 'A1');

        // Row 2 — the Eurovisión case: full three-instance chain, firm at cassation.
        $sheet->fromArray([
            '4', '2015', 'R-0203-2015', 'Gastos Festival Eurovisión 2015', 'Corporación RTVE', 'OOPP/EEPP',
            '', '6', '60/2016', '', '47/2016', '', 'Auto de admisión del recurso 75/2017', '1547/2017',
            'Firme', '', 'Abogados particulares',
        ], null, 'A2');
        $sheet->setCellValue('P2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2017-10-16')));
        $sheet->getCell('I2')->getHyperlink()->setUrl('https://www.consejodetransparencia.es/dam/sentencia-60-2016.pdf');
        $sheet->getCell('K2')->getHyperlink()->setUrl('http://www.poderjudicial.es/search/openDocument/abc123');
        $sheet->getCell('N2')->getHyperlink()->setUrl('https://www.consejodetransparencia.es/dam/sts-1547-2017.pdf');

        // Row 3 — accumulated resolutions incl. bis, single instance, not firm.
        $sheet->fromArray([
            '5', '2015', 'R-0059-2015 R-0060-2015 R-0060bis-2015', 'Aranceles de procuradores', 'Particular', 'C/A/E',
            '', '2', '116/2016', '', '', '', '', '',
            '', '', 'Abogacía del Estado',
        ], null, 'A3');

        // Row 4 — no sentencia at all (recurso still pending): must yield nothing.
        $sheet->fromArray([
            '6', '2024', 'R-0100-2024', 'Pendiente de señalamiento', 'Ministerio X', 'AGE',
            '', '1', '', '', '', '', '', '', '', '', '',
        ], null, 'A4');

        // Row 5 — decided cassation with the Firmeza column still EMPTY. Real case: the BOSCO
        // STS of 11/9/2025 existed while the CTBG had not yet filled the column.
        $sheet->fromArray([
            '7', '2018', 'R-0701-2018', 'Aplicación telemática del bono social', 'Fundación Civio', 'C/A/E',
            '', '8', '143/2021', '', '51/2022', '', '', '3826/2025', '', '', 'Abogados particulares',
        ], null, 'A5');

        self::$fixture = tempnam(sys_get_temp_dir(), 'ctbg_test_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save(self::$fixture);
    }

    private function reader(?string $file = null): CtbgRecursosXlsxReader
    {
        // DocumentFetcher is final (cannot be doubled); with a local file it is never called,
        // so a real instance over a MockHttpClient is both honest and sufficient.
        $fetcher = new DocumentFetcher(new MockHttpClient(), new NullLogger());

        return (new CtbgRecursosXlsxReader($fetcher, new NullLogger()))->withLocalFile($file ?? self::$fixture);
    }

    public function testReadsTheFullThreeInstanceChain(): void
    {
        $judgments = $this->reader()->fetchAll();
        $eurovision = array_values(array_filter($judgments, fn ($j) => $j->subject === 'Gastos Festival Eurovisión 2015'));

        self::assertCount(3, $eurovision);

        [$first, $appeal, $cassation] = $eurovision;

        self::assertSame('JCCA6/60/2016', $first->referenceNumber);
        self::assertSame(Judgment::COURT_JCCA, $first->court);
        self::assertSame(6, $first->courtNumber);
        self::assertNull($first->reviewedReferenceNumber);

        self::assertSame('AN/47/2016', $appeal->referenceNumber);
        self::assertSame('JCCA6/60/2016', $appeal->reviewedReferenceNumber);

        self::assertSame('TS/1547/2017', $cassation->referenceNumber);
        self::assertSame('AN/47/2016', $cassation->reviewedReferenceNumber);
        self::assertSame(['R/0203/2015'], $cassation->challengedResolutionRefs);
    }

    public function testOnlyTheLastRulingOfTheChainCarriesTheFirmeza(): void
    {
        // A cassated first-instance judgment is history, not the law of the case.
        $judgments = $this->reader()->fetchAll();
        $eurovision = array_values(array_filter($judgments, fn ($j) => $j->subject === 'Gastos Festival Eurovisión 2015'));

        self::assertFalse($eurovision[0]->isFinal);
        self::assertFalse($eurovision[1]->isFinal);
        self::assertTrue($eurovision[2]->isFinal);
        self::assertSame('2017-10-16', $eurovision[2]->finalDate?->format('Y-m-d'));
    }

    public function testCendojLinksAreFlaggedForTheBrowser(): void
    {
        $judgments = $this->reader()->fetchAll();
        $appeal = array_values(array_filter($judgments, fn ($j) => $j->referenceNumber === 'AN/47/2016'))[0];

        self::assertTrue($appeal->needsBrowser);
        self::assertStringContainsString('poderjudicial.es', (string) $appeal->sourceUrl);

        $first = array_values(array_filter($judgments, fn ($j) => $j->referenceNumber === 'JCCA6/60/2016'))[0];
        self::assertFalse($first->needsBrowser);
    }

    public function testAccumulatedResolutionsAreAllLinked(): void
    {
        $judgments = $this->reader()->fetchAll();
        $aranceles = array_values(array_filter($judgments, fn ($j) => $j->subject === 'Aranceles de procuradores'));

        self::assertCount(1, $aranceles);
        self::assertSame(['R/0059/2015', 'R/0060/2015', 'R/0060BIS/2015'], $aranceles[0]->challengedResolutionRefs);
    }

    public function testADecidedCassationIsFinalEvenWithTheFirmezaColumnEmpty(): void
    {
        // The Supreme Court is the end of the road: a decided cassation is firm by nature,
        // whether or not the CTBG has updated its listing yet (verified on the BOSCO case).
        $judgments = $this->reader()->fetchAll();
        $bosco = array_values(array_filter($judgments, fn ($j) => $j->subject === 'Aplicación telemática del bono social'));

        self::assertCount(3, $bosco);
        self::assertFalse($bosco[0]->isFinal, 'La instancia apelada no es firme.');
        self::assertFalse($bosco[1]->isFinal, 'La apelación casada no es firme.');
        self::assertTrue($bosco[2]->isFinal, 'Una casación RESUELTA es firme aunque la columna Firmeza esté vacía.');
        self::assertNull($bosco[2]->finalDate);
    }

    public function testAPendingRecursoYieldsNoJudgments(): void
    {
        $subjects = array_map(fn ($j) => $j->subject, $this->reader()->fetchAll());

        self::assertNotContains('Pendiente de señalamiento', $subjects);
    }

    public function testAReshuffledFileAbortsLoudly(): void
    {
        // Reading the wrong column silently would poison every judgment at once.
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Nº', 'Año', 'Columna nueva desconocida'], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ctbg_bad_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        $reader = $this->reader($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cambiado de formato/');

        $reader->fetchAll();
    }
}
