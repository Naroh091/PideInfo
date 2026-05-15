<?php

namespace App\Service\AccessRequest;

use App\Entity\ApplicableLaw;

class DeadlineCalculator
{
    public function calculate(\DateTimeImmutable $sentDate, ApplicableLaw $law): \DateTimeImmutable
    {
        return $this->addDuration(
            $sentDate,
            $law->getResponseDeadlineValue(),
            $law->getResponseDeadlineUnit()
        );
    }

    public function calculateExtension(\DateTimeImmutable $currentDeadline, ApplicableLaw $law): \DateTimeImmutable
    {
        return $this->addDuration(
            $currentDeadline,
            $law->getExtensionValue(),
            $law->getExtensionUnit()
        );
    }

    public function calculateComplaintDeadline(\DateTimeImmutable $resolutionDate, ApplicableLaw $law): \DateTimeImmutable
    {
        // Complaint deadlines are typically in business days
        return $this->addBusinessDays($resolutionDate, $law->getComplaintDeadlineDays());
    }

    public function addDuration(\DateTimeImmutable $startDate, int $value, string $unit): \DateTimeImmutable
    {
        return match ($unit) {
            ApplicableLaw::UNIT_MONTHS => $this->addMonths($startDate, $value),
            ApplicableLaw::UNIT_DAYS => $startDate->modify("+{$value} days"),
            ApplicableLaw::UNIT_BUSINESS_DAYS => $this->addBusinessDays($startDate, $value),
            default => $startDate->modify("+{$value} days"),
        };
    }

    /**
     * Add natural months (calendar months)
     * Example: Jan 15 + 1 month = Feb 15
     * Special case: Jan 31 + 1 month = Feb 28 (or 29 in leap year)
     */
    public function addMonths(\DateTimeImmutable $startDate, int $months): \DateTimeImmutable
    {
        return $startDate->modify("+{$months} months");
    }

    public function addBusinessDays(\DateTimeImmutable $startDate, int $days): \DateTimeImmutable
    {
        $deadline = $startDate;
        $addedDays = 0;

        while ($addedDays < $days) {
            $deadline = $deadline->modify('+1 day');
            if (!$this->isHoliday($deadline) && !$this->isWeekend($deadline)) {
                $addedDays++;
            }
        }

        return $deadline;
    }

    /**
     * Count business days between two dates (excluding weekends and holidays).
     */
    public function countBusinessDays(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): int
    {
        $count = 0;
        $current = $startDate;

        while ($current < $endDate) {
            $current = $current->modify('+1 day');
            if (!$this->isHoliday($current) && !$this->isWeekend($current)) {
                $count++;
            }
        }

        return $count;
    }

    private function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array($date->format('N'), ['6', '7'], true);
    }

    private function isHoliday(\DateTimeImmutable $date): bool
    {
        $holidays = $this->getSpanishNationalHolidays($date->format('Y'));
        return in_array($date->format('Y-m-d'), $holidays, true);
    }

    /**
     * @return array<string>
     */
    private function getSpanishNationalHolidays(string $year): array
    {
        $holidays = [
            "$year-01-01", // Año Nuevo
            "$year-01-06", // Epifanía del Señor
            "$year-05-01", // Fiesta del Trabajo
            "$year-08-15", // Asunción de la Virgen
            "$year-10-12", // Fiesta Nacional de España
            "$year-11-01", // Todos los Santos
            "$year-12-06", // Día de la Constitución
            "$year-12-08", // Inmaculada Concepción
            "$year-12-25", // Navidad
        ];

        // Add Easter-based holidays (Viernes Santo, Jueves Santo)
        $easter = $this->calculateEaster((int) $year);
        $holidays[] = $easter->modify('-2 days')->format('Y-m-d'); // Viernes Santo
        $holidays[] = $easter->modify('-3 days')->format('Y-m-d'); // Jueves Santo (most regions)

        return $holidays;
    }

    /**
     * Easter Sunday (Gregorian) via the Anonymous/Meeus algorithm. Avoids the
     * `ext-calendar` extension since it isn't shipped on every PHP build.
     */
    private function calculateEaster(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
