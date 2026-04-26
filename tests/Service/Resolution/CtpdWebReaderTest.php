<?php

declare(strict_types=1);

namespace App\Tests\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use App\Service\Resolution\CtpdWebReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CtpdWebReaderTest extends TestCase
{
    public function testParseListingPageAssignsOutcomeFromHeading(): void
    {
        $entries = $this->invokeParseListingPage($this->loadFixture('ctpd_listing_new.html'));

        $byRef = [];
        foreach ($entries as $dto) {
            $byRef[$dto->referenceNumber] = $dto;
        }

        $this->assertArrayHasKey('063/2025', $byRef);
        $this->assertSame(Resolution::OUTCOME_FAVORABLE, $byRef['063/2025']->outcome);
        $this->assertSame(Resolution::OUTCOME_PARTIAL, $byRef['677/2025']->outcome);
        $this->assertSame(Resolution::OUTCOME_UNFAVORABLE, $byRef['120/2025']->outcome);
        $this->assertSame(Resolution::OUTCOME_WITHDRAWAL, $byRef['055/2025']->outcome);
        $this->assertSame(Resolution::OUTCOME_INADMISSIBLE, $byRef['080/2025']->outcome);
        $this->assertSame(Resolution::OUTCOME_LOSS_OF_PURPOSE, $byRef['500/2025']->outcome);

        $this->assertSame(Resolution::SOURCE_CTPD, $byRef['063/2025']->source);
        $this->assertSame(Resolution::SCOPE_AUTONOMOUS, $byRef['063/2025']->scope);
        $this->assertSame('CTPD', $byRef['063/2025']->complaintOrganismShortName);
        $this->assertSame('Comunidad de Madrid', $byRef['063/2025']->autonomousCommunityName);
    }

    public function testParseLinkEntryAcceptsDocsAssetsPathWithoutAnomaly(): void
    {
        $entries = $this->invokeParseListingPage($this->loadFixture('ctpd_listing_new.html'));

        $docsAssets = $this->findByReference($entries, '063/2025');
        $this->assertNotNull($docsAssets);
        $this->assertNull($docsAssets->sourceMetadata);

        $sitesDefault = $this->findByReference($entries, '400/2025');
        $this->assertNotNull($sitesDefault);
        $this->assertNull($sitesDefault->sourceMetadata);
    }

    public function testInferOutcomeFromHeadingWithUnknownTitleReturnsNull(): void
    {
        $method = new ReflectionMethod(CtpdWebReader::class, 'inferOutcomeFromHeading');

        $this->assertNull($method->invoke(null, 'OTRA SECCIÓN'));
        $this->assertNull($method->invoke(null, ''));
        $this->assertSame(Resolution::OUTCOME_PARTIAL, $method->invoke(null, 'estimatorias parciales'));
        $this->assertSame(Resolution::OUTCOME_LOSS_OF_PURPOSE, $method->invoke(null, 'Archivos por pérdida de objeto'));
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function invokeParseListingPage(string $html): array
    {
        $reader = new CtpdWebReader($this->createStub(HttpClientInterface::class), new NullLogger());
        $method = new ReflectionMethod(CtpdWebReader::class, 'parseListingPage');

        return $method->invoke($reader, $html);
    }

    /**
     * @param array<int, ResolutionData> $entries
     */
    private function findByReference(array $entries, string $reference): ?ResolutionData
    {
        foreach ($entries as $dto) {
            if ($dto->referenceNumber === $reference) {
                return $dto;
            }
        }

        return null;
    }

    private function loadFixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail('Could not load fixture: ' . $path);
        }

        return $contents;
    }
}
