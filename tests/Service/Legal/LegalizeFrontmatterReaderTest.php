<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Service\Legal\LegalizeFrontmatterReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LegalizeFrontmatterReaderTest extends TestCase
{
    private LegalizeFrontmatterReader $reader;

    protected function setUp(): void
    {
        $this->reader = new LegalizeFrontmatterReader(new NullLogger());
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../../Fixtures/legalize/' . $name;
    }

    public function testReadsTheFrontmatterOfARealNorm(): void
    {
        $meta = $this->reader->read($this->fixture('mini-lcsp.md'));

        self::assertIsArray($meta);
        self::assertSame('BOE-A-2017-12902', $meta['identifier']);
        self::assertSame('9/2017', $meta['official_number']);
        self::assertSame('ley', $meta['rank']);
        self::assertSame('in_force', $meta['status']);
        // A title full of colons and commas is the norm, not the exception.
        self::assertStringContainsString('Contratos del Sector Público', $meta['title']);
        self::assertSame(['Contratación administrativa', 'Sector público'], $meta['subjects']);
    }

    public function testBrokenYamlYieldsNullInsteadOfThrowing(): void
    {
        // One corrupt file must never take down a sync that walks 40.000 of them.
        self::assertNull($this->reader->read($this->fixture('broken-frontmatter.md')));
    }

    public function testMissingFileYieldsNull(): void
    {
        self::assertNull($this->reader->read($this->fixture('does-not-exist.md')));
    }

    public function testDatesBecomeImmutable(): void
    {
        $date = LegalizeFrontmatterReader::toDate('2017-11-09');

        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2017-11-09', $date->format('Y-m-d'));

        self::assertNull(LegalizeFrontmatterReader::toDate('no es una fecha'));
        self::assertNull(LegalizeFrontmatterReader::toDate(null));
    }

    public function testSubjectsAreCoercedToAListOfStrings(): void
    {
        self::assertSame(['uno', 'dos'], LegalizeFrontmatterReader::toStringList(['uno', 'dos']));
        self::assertSame(['solo'], LegalizeFrontmatterReader::toStringList('solo'));
        self::assertSame([], LegalizeFrontmatterReader::toStringList(null));
        self::assertSame(['ok'], LegalizeFrontmatterReader::toStringList(['ok', 42, null]));
    }
}
