<?php

declare(strict_types=1);

namespace App\Tests\Service\Judgment;

use App\Entity\Judgment;
use App\Entity\Resolution;
use App\Repository\JudgmentRepository;
use App\Service\Judgment\JudicialStatus;
use App\Service\Judgment\ResolutionJudicialStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The only writer of resolution.judicial_status. What this pins: it reclassifies from the
 * resolution\'s WHOLE chain, never from the judgment being saved — otherwise the confirmation
 * handed down in first instance would overwrite the annulment won in cassation.
 */
final class ResolutionJudicialStatusUpdaterTest extends TestCase
{
    public function testItReclassifiesFromTheWholeChainNotFromOneJudgment(): void
    {
        $resolution = new Resolution();

        $instance = (new Judgment())->setReferenceNumber('JCCA1/10/2016')->setCourt(Judgment::COURT_AN)
            ->setJudgmentNumber('10/2016')->setInstance(Judgment::INSTANCE_FIRST)
            ->setIsFinal(true)->setResolutionEffect(Judgment::EFFECT_CONFIRMA);

        $cassation = (new Judgment())->setReferenceNumber('TS/3826/2025')->setCourt(Judgment::COURT_TS)
            ->setJudgmentNumber('3826/2025')->setInstance(Judgment::INSTANCE_CASSATION)
            ->setIsFinal(true)->setResolutionEffect(Judgment::EFFECT_ANULA)
            ->setTransparencyStance(Judgment::STANCE_PRO_ACCESS);

        $instance->addResolution($resolution);
        $cassation->addResolution($resolution);

        $judgments = $this->createMock(JudgmentRepository::class);
        $judgments->method('findByResolutionIds')->willReturn([
            (string) $resolution->getId() => [$instance, $cassation],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $changed = (new ResolutionJudicialStatusUpdater($entityManager, $judgments))
            ->refreshResolutions([$resolution]);

        self::assertSame(1, $changed);
        // Saving the INSTANCE judgment must still leave the resolution annulled: the Supreme
        // Court had the last word, and it was pro-access.
        self::assertSame(JudicialStatus::ANNULLED_PRO_ACCESS, $resolution->getJudicialStatus());
    }

    public function testAnUnchangedStatusIsNotRewritten(): void
    {
        $resolution = new Resolution();
        $resolution->setJudicialStatus(JudicialStatus::NOT_CHALLENGED);

        $judgments = $this->createMock(JudgmentRepository::class);
        $judgments->method('findByResolutionIds')->willReturn([]);

        // No change, no flush: this runs on every judgment import, and a pointless UPDATE would
        // wake ResolutionIndexListener and reindex the row for nothing.
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $changed = (new ResolutionJudicialStatusUpdater($entityManager, $judgments))
            ->refreshResolutions([$resolution]);

        self::assertSame(0, $changed);
    }
}
