<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRequestComplaint;
use App\Entity\HearingProcess;
use PHPUnit\Framework\TestCase;

final class HearingProcessTest extends TestCase
{
    public function testIsActiveWhenEndDateIsTodayOrFuture(): void
    {
        $active = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-2 days'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $expired = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-20 days'))
            ->setEndDate(new \DateTimeImmutable('-1 day'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $this->assertTrue($active->isActive());
        $this->assertFalse($expired->isActive());
    }

    public function testDaysUntilEnd(): void
    {
        $hearing = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today +5 days'))
            ->setHearingDays(5)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_CALENDAR);

        $this->assertSame(5, $hearing->getDaysUntilEnd());
    }

    public function testComplaintActiveHearingProcessPicksLatestNonExpired(): void
    {
        $complaint = new AccessRequestComplaint();

        $expired = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-30 days'))
            ->setEndDate(new \DateTimeImmutable('-10 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $activeShort = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today +3 days'))
            ->setHearingDays(3)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $activeLong = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('today +10 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $complaint->addHearingProcess($expired);
        $complaint->addHearingProcess($activeShort);
        $complaint->addHearingProcess($activeLong);

        $this->assertSame($activeLong, $complaint->getActiveHearingProcess());
        // El más relevante para mostrar: el activo si lo hay.
        $this->assertSame($activeLong, $complaint->getLatestHearingProcess());
    }

    public function testComplaintLatestHearingProcessWhenAllExpired(): void
    {
        $complaint = new AccessRequestComplaint();

        $older = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-40 days'))
            ->setEndDate(new \DateTimeImmutable('-30 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $newer = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-20 days'))
            ->setEndDate(new \DateTimeImmutable('-5 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $complaint->addHearingProcess($older);
        $complaint->addHearingProcess($newer);

        $this->assertNull($complaint->getActiveHearingProcess());
        $this->assertSame($newer, $complaint->getLatestHearingProcess());
    }
}
