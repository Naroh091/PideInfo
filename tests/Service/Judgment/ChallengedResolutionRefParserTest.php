<?php

declare(strict_types=1);

namespace App\Tests\Service\Judgment;

use App\Service\Judgment\ChallengedResolutionRefParser;
use PHPUnit\Framework\TestCase;

/**
 * Every case here is a REAL value from the live RecursosjudicialesAE.xlsx, not an invention.
 */
final class ChallengedResolutionRefParserTest extends TestCase
{
    public function testTheCommonCase(): void
    {
        $result = ChallengedResolutionRefParser::parse('R-0105-2015');

        self::assertSame(['R/0105/2015'], $result['refs']);
        self::assertSame([], $result['unparsed']);
    }

    public function testAccumulatedResolutionsIncludingBis(): void
    {
        $result = ChallengedResolutionRefParser::parse('R-0059-2015 R-0060-2015 R-0060bis-2015');

        self::assertSame(['R/0059/2015', 'R/0060/2015', 'R/0060BIS/2015'], $result['refs']);
    }

    public function testParenthesisedReference(): void
    {
        self::assertSame(['R/0168/2016'], ChallengedResolutionRefParser::parse('(R-0168-2016)')['refs']);
    }

    public function testTheWordYIsProseNotAReference(): void
    {
        $result = ChallengedResolutionRefParser::parse('R-0105-2015 y R-0107-2015');

        self::assertSame(['R/0105/2015', 'R/0107/2015'], $result['refs']);
        self::assertSame([], $result['unparsed']);
    }

    public function testUnderscoreSeparator(): void
    {
        self::assertSame(['R/0572/2018'], ChallengedResolutionRefParser::parse('R-0572_2018')['refs']);
    }

    public function testOverPaddedNumbersAreCanonicalised(): void
    {
        // "R-00610-2021" is how the 2021 rows are written; resolutions store R/0610/2021.
        self::assertSame(['R/0610/2021'], ChallengedResolutionRefParser::parse('R-00610-2021')['refs']);
    }

    public function testSlashesAndPrefixVariantsAreAccepted(): void
    {
        self::assertSame(['R/0105/2015'], ChallengedResolutionRefParser::parse('R/0105/2015')['refs']);
        self::assertSame(['RT/0538/2021'], ChallengedResolutionRefParser::parse('RT-0538-2021')['refs']);
    }

    public function testGarbageIsReportedNotDropped(): void
    {
        $result = ChallengedResolutionRefParser::parse('R-0105-2015 expediente-sin-formato');

        self::assertSame(['R/0105/2015'], $result['refs']);
        self::assertSame(['expediente-sin-formato'], $result['unparsed']);
    }

    public function testDuplicatesCollapse(): void
    {
        self::assertSame(['R/0088/2016'], ChallengedResolutionRefParser::parse('(R-0088-2016) R-0088-2016')['refs']);
    }

    public function testEmptyCell(): void
    {
        $result = ChallengedResolutionRefParser::parse('  ');

        self::assertSame([], $result['refs']);
        self::assertSame([], $result['unparsed']);
    }
}
