<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Repository\ApplicableLawRepository;
use App\Service\Submission\ApplicableLawResolver;
use PHPUnit\Framework\TestCase;

final class ApplicableLawResolverTest extends TestCase
{
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

        $this->assertSame($stateLaw, (new ApplicableLawResolver($repo))->resolveFor($body));
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

        $this->assertSame($catLaw, (new ApplicableLawResolver($repo))->resolveFor($body));
    }

    public function testFallsBackToStateLawWhenCommunityHasNoLawConfigured(): void
    {
        $community = (new AutonomousCommunity())->setName('Galicia')->setCode('GAL');
        $stateLaw = (new ApplicableLaw())->setName('Ley 19/2013')->setShortCode('LTAIPBG');

        $repo = $this->createMock(ApplicableLawRepository::class);
        $repo->method('findByAutonomousCommunity')->with($community)->willReturn(null);
        $repo->method('findStateLaw')->willReturn($stateLaw);

        $body = (new PublicBody())->setName('Xunta')->setAutonomousCommunity($community);

        $this->assertSame($stateLaw, (new ApplicableLawResolver($repo))->resolveFor($body));
    }

    public function testDeadlineLabelMonthsAndNegativeSilence(): void
    {
        $law = (new ApplicableLaw())
            ->setName('x')
            ->setShortCode('x')
            ->setResponseDeadlineValue(1)
            ->setResponseDeadlineUnit('months')
            ->setSilenceIsPositive(false);

        $resolver = new ApplicableLawResolver($this->createStub(ApplicableLawRepository::class));
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

        $resolver = new ApplicableLawResolver($this->createStub(ApplicableLawRepository::class));
        $this->assertSame('20 días hábiles (silencio positivo)', $resolver->deadlineLabel($law));
    }
}
