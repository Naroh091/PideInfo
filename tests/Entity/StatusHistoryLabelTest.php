<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StatusHistory;
use PHPUnit\Framework\TestCase;

final class StatusHistoryLabelTest extends TestCase
{
    /**
     * @dataProvider statusLabelProvider
     */
    public function testToStatusLabelTranslatesPrimaryStatuses(string $toStatus, string $expected): void
    {
        $history = (new StatusHistory())
            ->setStatusType(StatusHistory::TYPE_STATUS)
            ->setToStatus($toStatus);

        self::assertSame($expected, $history->getToStatusLabel());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statusLabelProvider(): iterable
    {
        // Posiciones vivas (vocabulario del rediseño de 6 posiciones).
        yield 'pending' => ['pending', 'Borrador'];
        yield 'granted' => ['granted', 'Pendiente de recepción'];
        yield 'finished' => ['finished', 'Finalizada'];
        // Posiciones deprecated: las filas históricas siguen traduciéndose
        // (regresión original: caían al valor crudo, issue #79).
        yield 'partially granted' => ['partially_granted', 'Estimación parcial'];
        yield 'inadmitted' => ['inadmitted', 'Inadmitida'];
        yield 'granted completed' => ['granted_completed', 'Completada'];
        yield 'denied' => ['denied', 'Denegada'];
    }
}
