<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest;

use App\Service\AccessRequest\DeadlineCalculator;
use PHPUnit\Framework\TestCase;

final class DeadlineCalculatorTest extends TestCase
{
    private DeadlineCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DeadlineCalculator();
    }

    public function testHearingDeadlineCalendarDays(): void
    {
        // Notificado el lunes 2026-06-01, 10 días naturales: cuenta del 2 al 11.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-01'), 10, 'calendar',
        );

        $this->assertSame('2026-06-11', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineBusinessDaysSkipsWeekends(): void
    {
        // Notificado el viernes 2026-06-05, 10 días hábiles.
        // Cuenta desde el lunes 8: 8-12 (5) + 15-19 (10) → 2026-06-19.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'business',
        );

        $this->assertSame('2026-06-19', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineBusinessDaysSkipsNationalHolidays(): void
    {
        // Notificado el viernes 2026-12-04, 3 días hábiles.
        // Sábado 5 y domingo 6 fuera; lunes 7 cuenta (1); martes 8 festivo
        // (Inmaculada); miércoles 9 (2); jueves 10 (3) → 2026-12-10.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-12-04'), 3, 'business',
        );

        $this->assertSame('2026-12-10', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineUnknownTypeFallsBackToBusiness(): void
    {
        $business = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'business',
        );
        $unknown = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'lo-que-sea',
        );

        $this->assertSame($business->format('Y-m-d'), $unknown->format('Y-m-d'));
    }
}
