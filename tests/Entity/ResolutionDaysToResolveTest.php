<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Resolution;
use PHPUnit\Framework\TestCase;

final class ResolutionDaysToResolveTest extends TestCase
{
    public function testCountsTheDaysBetweenClaimAndResolution(): void
    {
        self::assertSame(85, $this->resolution('2017-01-03', '2017-03-29')->getDaysToResolve());
    }

    public function testSameDayResolutionIsZeroDays(): void
    {
        self::assertSame(0, $this->resolution('2024-05-01', '2024-05-01')->getDaysToResolve());
    }

    public function testNullWhenEitherDateIsMissing(): void
    {
        self::assertNull($this->resolution(null, '2024-05-01')->getDaysToResolve());
        self::assertNull($this->resolution('2024-05-01', null)->getDaysToResolve());
    }

    /**
     * Some sources publish a resolution date earlier than the claim date. DateInterval::$days
     * is unsigned, so without a guard those rows report a plausible positive duration.
     */
    public function testNullWhenTheResolutionPredatesTheClaim(): void
    {
        self::assertNull($this->resolution('2024-05-10', '2024-05-01')->getDaysToResolve());
    }

    private function resolution(?string $claimDate, ?string $resolutionDate): Resolution
    {
        $resolution = new Resolution();

        if ($claimDate !== null) {
            $resolution->setClaimDate(new \DateTimeImmutable($claimDate));
        }
        if ($resolutionDate !== null) {
            $resolution->setResolutionDate(new \DateTimeImmutable($resolutionDate));
        }

        return $resolution;
    }
}
