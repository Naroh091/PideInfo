<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\RegDestinationRepository;
use App\Service\Submission\ApplicableLawResolver;
use PHPUnit\Framework\TestCase;

final class ApplicableLawResolverTest extends TestCase
{
    private function makeResolver(
        ApplicableLawRepository $lawRepo,
        ?RegDestinationRepository $regRepo = null,
        ?AutonomousCommunityRepository $acRepo = null,
    ): ApplicableLawResolver {
        return new ApplicableLawResolver(
            $lawRepo,
            $regRepo ?? $this->createStub(RegDestinationRepository::class),
            $acRepo ?? $this->createStub(AutonomousCommunityRepository::class),
        );
    }

    public function testFallsBackToStateLawWhenBodyHasNoCommunity(): void
    {
        $stateLaw = (new ApplicableLaw())
            ->setName('Ley 19/2013')
            ->setShortCode('LTAIPBG')
            ->setResponseDeadlineValue(1)
            ->setResponseDeadlineUnit('months');

        $repo = $this->createMock(ApplicableLawRepository::class);
        $repo->expects($this->never())->method('findByAutonomousCommunity');
        $repo->method('findStateLaw')->willReturn($stateLaw);

        $body = (new PublicBody())->setName('Ministerio X');

        $this->assertSame($stateLaw, $this->makeResolver($repo)->resolveFor($body));
    }

    public function testUsesCommunityLawWhenBodyHasCommunity(): void
    {
        $community = (new AutonomousCommunity())->setName('Cataluña')->setCode('CAT');
        $catLaw = (new ApplicableLaw())
            ->setName('Llei 19/2014')
            ->setShortCode('LTC')
            ->setResponseDeadlineValue(1)
            ->setResponseDeadlineUnit('months')
            ->setSilenceIsPositive(true);

        $repo = $this->createMock(ApplicableLawRepository::class);
        $repo->expects($this->once())
            ->method('findByAutonomousCommunity')
            ->with($community)
            ->willReturn($catLaw);
        $repo->expects($this->never())->method('findStateLaw');

        $body = (new PublicBody())->setName('Generalitat')->setAutonomousCommunity($community);

        $this->assertSame($catLaw, $this->makeResolver($repo)->resolveFor($body));
    }

    public function testFallsBackToStateLawWhenCommunityHasNoLawConfigured(): void
    {
        $community = (new AutonomousCommunity())->setName('Galicia')->setCode('GAL');
        $stateLaw = (new ApplicableLaw())->setName('Ley 19/2013')->setShortCode('LTAIPBG');

        $repo = $this->createMock(ApplicableLawRepository::class);
        $repo->method('findByAutonomousCommunity')->with($community)->willReturn(null);
        $repo->method('findStateLaw')->willReturn($stateLaw);

        $body = (new PublicBody())->setName('Xunta')->setAutonomousCommunity($community);

        $this->assertSame($stateLaw, $this->makeResolver($repo)->resolveFor($body));
    }

    /**
     * Regression: a state body (e.g. a ministry) has no `autonomousCommunity`
     * seeded, and every one of its REG destinations is a state-level registry
     * office. Those offices physically sit in one community (Madrid, the
     * capital), but office geography is not legal jurisdiction — the body must
     * keep the state-level Ley 19/2013, not inherit Madrid's regional law.
     */
    public function testStateLevelBodyKeepsStateLawEvenWhenAllRegisterOfficesShareACommunity(): void
    {
        $stateLaw = (new ApplicableLaw())->setName('Ley 19/2013')->setShortCode('LTAIPBG');

        $lawRepo = $this->createMock(ApplicableLawRepository::class);
        $lawRepo->expects($this->never())->method('findByAutonomousCommunity');
        $lawRepo->method('findStateLaw')->willReturn($stateLaw);

        $regRepo = $this->createMock(RegDestinationRepository::class);
        $regRepo->method('bodyHasStateLevelDestination')->willReturn(true);
        // Once we know the body is state-level, geography is irrelevant — we
        // must not even look at the per-destination communities.
        $regRepo->expects($this->never())->method('findDistinctComunidades');

        $body = (new PublicBody())->setName('Ministerio para la Transformación Digital y de la Función Pública');

        $resolver = $this->makeResolver($lawRepo, $regRepo);
        $this->assertSame($stateLaw, $resolver->resolveFor($body));
    }

    /**
     * The legacy fallback must still work for non-state bodies: a local body
     * that never had `autonomousCommunity` seeded, whose REG destinations all
     * sit in one community, still inherits that community's transparency law.
     */
    public function testDerivesCommunityLawForNonStateBodyFromRegDestinations(): void
    {
        $madrid = (new AutonomousCommunity())->setName('Comunidad de Madrid')->setCode('MAD');
        $madridLaw = (new ApplicableLaw())->setName('Ley 10/2019')->setShortCode('LTCM');

        $lawRepo = $this->createMock(ApplicableLawRepository::class);
        $lawRepo->method('findByAutonomousCommunity')->with($madrid)->willReturn($madridLaw);
        $lawRepo->expects($this->never())->method('findStateLaw');

        $regRepo = $this->createMock(RegDestinationRepository::class);
        $regRepo->method('bodyHasStateLevelDestination')->willReturn(false);
        $regRepo->method('findDistinctComunidades')->willReturn(['Comunidad de Madrid']);

        $acRepo = $this->createMock(AutonomousCommunityRepository::class);
        $acRepo->method('findByName')->with('Comunidad de Madrid')->willReturn($madrid);

        $body = (new PublicBody())->setName('Ayuntamiento de Madrid');

        $resolver = $this->makeResolver($lawRepo, $regRepo, $acRepo);
        $this->assertSame($madridLaw, $resolver->resolveFor($body));
    }

    public function testDeadlineLabelMonthsAndNegativeSilence(): void
    {
        $law = (new ApplicableLaw())
            ->setName('x')
            ->setShortCode('x')
            ->setResponseDeadlineValue(1)
            ->setResponseDeadlineUnit('months')
            ->setSilenceIsPositive(false);

        $resolver = $this->makeResolver($this->createStub(ApplicableLawRepository::class));
        $this->assertSame('1 mes (silencio negativo)', $resolver->deadlineLabel($law));
    }

    public function testDeadlineLabelBusinessDaysAndPositiveSilence(): void
    {
        $law = (new ApplicableLaw())
            ->setName('x')
            ->setShortCode('x')
            ->setResponseDeadlineValue(20)
            ->setResponseDeadlineUnit('business_days')
            ->setSilenceIsPositive(true);

        $resolver = $this->makeResolver($this->createStub(ApplicableLawRepository::class));
        $this->assertSame('20 días hábiles (silencio positivo)', $resolver->deadlineLabel($law));
    }
}
