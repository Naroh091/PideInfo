<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplaintOrganism;
use PHPUnit\Framework\TestCase;

/**
 * Unidad pura de la metadata de extinción de un órgano de garantía:
 * flag `extinct`, fecha de extinción y la relación auto-referenciada
 * sucesor ↔ predecesores.
 */
final class ComplaintOrganismExtinctionTest extends TestCase
{
    public function testIsActiveByDefault(): void
    {
        $organism = new ComplaintOrganism();
        $this->assertFalse($organism->isExtinct());
        $this->assertNull($organism->getExtinctionDate());
        $this->assertNull($organism->getSuccessor());
        $this->assertCount(0, $organism->getPredecessors());
    }

    public function testExtinctionDateRoundTrip(): void
    {
        $date = new \DateTimeImmutable('2023-05-01');
        $organism = (new ComplaintOrganism())
            ->setExtinct(true)
            ->setExtinctionDate($date);

        $this->assertTrue($organism->isExtinct());
        $this->assertSame($date, $organism->getExtinctionDate());
    }

    public function testAddPredecessorSyncsInverseSide(): void
    {
        $successor = (new ComplaintOrganism())->setName('CTBG')->setShortName('CTBG');
        $extinct = (new ComplaintOrganism())->setName('Consejo extinguido')->setShortName('OLD')->setExtinct(true);

        $successor->addPredecessor($extinct);

        $this->assertSame($successor, $extinct->getSuccessor());
        $this->assertCount(1, $successor->getPredecessors());
        $this->assertTrue($successor->getPredecessors()->contains($extinct));
    }

    public function testAddPredecessorIsIdempotent(): void
    {
        $successor = new ComplaintOrganism();
        $extinct = new ComplaintOrganism();

        $successor->addPredecessor($extinct);
        $successor->addPredecessor($extinct);

        $this->assertCount(1, $successor->getPredecessors());
    }

    public function testRemovePredecessorClearsSuccessor(): void
    {
        $successor = new ComplaintOrganism();
        $extinct = new ComplaintOrganism();

        $successor->addPredecessor($extinct);
        $successor->removePredecessor($extinct);

        $this->assertNull($extinct->getSuccessor());
        $this->assertCount(0, $successor->getPredecessors());
    }

    public function testSetSuccessorDirectly(): void
    {
        $successor = (new ComplaintOrganism())->setShortName('CTBG');
        $extinct = (new ComplaintOrganism())->setExtinct(true)->setSuccessor($successor);

        $this->assertSame($successor, $extinct->getSuccessor());
    }
}
