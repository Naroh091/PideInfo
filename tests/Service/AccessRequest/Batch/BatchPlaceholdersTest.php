<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest\Batch;

use App\Service\AccessRequest\Batch\BatchPlaceholders;
use PHPUnit\Framework\TestCase;

final class BatchPlaceholdersTest extends TestCase
{
    use BatchFixtures;

    public function testDetectCountsMarkersInOrderOfAppearance(): void
    {
        $counts = BatchPlaceholders::detect("Al [[ORGANISMO]], al amparo de [[LEY_APLICABLE]].\nEl [[ ORGANISMO ]] y [[INVENTADO]].");

        self::assertSame(['ORGANISMO' => 2, 'LEY_APLICABLE' => 1, 'INVENTADO' => 1], $counts);
        self::assertSame([], BatchPlaceholders::detect('Sin marcadores [ORGANISMO] ni {{x}}'));
    }

    public function testFillUsesEachRecipientValuesAndKeepsUnknownMarkers(): void
    {
        $text = 'Solicito al [[ORGANISMO]] ([[UNIDAD]]), al amparo de la [[LEY_APLICABLE]], [[OTRO]].';

        $portal = $this->draft('Ministerio de Hacienda', 'Ley 19/2013');
        self::assertSame(
            'Solicito al Ministerio de Hacienda (Ministerio de Hacienda), al amparo de la Ley 19/2013, [[OTRO]].',
            BatchPlaceholders::fill($text, $portal),
            'sin unidad REG, [[UNIDAD]] cae al organismo; los desconocidos quedan visibles',
        );

        $reg = $this->draft('Xunta de Galicia', 'Lei 1/2016', 'Consellería de Medio Ambiente');
        self::assertStringContainsString('Xunta de Galicia (Consellería de Medio Ambiente)', BatchPlaceholders::fill($text, $reg));
        self::assertStringContainsString('Lei 1/2016', BatchPlaceholders::fill($text, $reg));
    }

    public function testCatalogOnlyHoldsMarkersTheFillerKnows(): void
    {
        $values = BatchPlaceholders::valuesFor($this->draft('A', 'B'));

        self::assertSame(array_keys(BatchPlaceholders::catalog()), array_keys($values));
    }
}
