<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The gate that stands between a broken PDF font and a fabricated legal corpus.
 *
 * R/0169/2020 embeds a subset font with no ToUnicode map, so poppler returns a substitution
 * cipher — «ZĞƐŽůƵĐŝſŶ ϭϲϵͬϮϬϮϬ» for «Resolución 169/2020». Those glyphs are letters, so the old
 * per-page check (which only COUNTED characters) declared the pages healthy, skipped the vision
 * fallback, and handed the cipher to an LLM. The LLM did not refuse it: it guessed a decoding and
 * published it. The resolution went live as «Crás en edificios de la Guardia Civil en la provincia
 * de Sordá», citing an «artículo 125» and a «Real Decreto 111/2014» that do not exist.
 *
 * No LLM and no network here: the point is that the cipher never leaves this class.
 */
final class PdfTextExtractorTest extends TestCase
{
    private const MOJIBAKE_PDF = __DIR__ . '/../../Fixtures/Pdf/mojibake-font-layer.pdf';

    public function testAnUnreadableTextLayerIsBlankedSoItGetsOcrd(): void
    {
        $pages = (new PdfTextExtractor())->extractPageTexts(self::MOJIBAKE_PDF);

        self::assertNotEmpty($pages, 'el PDF debería tener páginas');

        foreach ($pages as $number => $text) {
            self::assertSame('', trim($text), sprintf('la página %d debería venir en blanco (capa ilegible)', $number));
        }
    }

    public function testTheCipherNeverReachesACaller(): void
    {
        $joined = implode(' ', (new PdfTextExtractor())->extractPageTexts(self::MOJIBAKE_PDF));

        // Any of these surviving means an LLM downstream is about to "decode" them.
        foreach (['ZĞƐŽůƵĐŝſŶ', 'ϭϲϵ', 'KKZ', 'ĚĞ ůĂ'] as $cipher) {
            self::assertStringNotContainsString($cipher, $joined);
        }
    }
}
