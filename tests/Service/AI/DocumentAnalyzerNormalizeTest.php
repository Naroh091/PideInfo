<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Enum\DocumentType;
use App\Service\AI\DocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DocumentAnalyzerNormalizeTest extends TestCase
{
    public function testNormalizesHearingFieldsForAudienciaDocument(): void
    {
        $result = $this->normalize([
            'documentType' => 'audiencia',
            'hearing_days' => '10',
            'hearing_days_type' => 'business',
        ]);

        $this->assertSame(DocumentType::Audiencia, $result['documentType']);
        $this->assertSame(10, $result['hearing_days']);
        $this->assertSame('business', $result['hearing_days_type']);
    }

    public function testHearingDaysTypeDefaultsToBusinessWhenMissingOrInvalid(): void
    {
        $missing = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15]);
        $invalid = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15, 'hearing_days_type' => 'lunar']);

        $this->assertSame('business', $missing['hearing_days_type']);
        $this->assertSame('business', $invalid['hearing_days_type']);
    }

    public function testHearingDaysNullWhenAbsentOrNotPositive(): void
    {
        $absent = $this->normalize(['documentType' => 'audiencia']);
        $zero = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 0]);
        $garbage = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 'muchos']);

        $this->assertNull($absent['hearing_days']);
        $this->assertNull($zero['hearing_days']);
        $this->assertNull($garbage['hearing_days']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $analyzer = (new ReflectionClass(DocumentAnalyzer::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentAnalyzer::class, 'normalizeDocumentAnalysis');

        return $method->invoke($analyzer, $data);
    }
}
