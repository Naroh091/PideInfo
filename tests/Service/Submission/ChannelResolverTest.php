<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\AgentTask;
use App\Entity\PublicBody;
use App\Service\Submission\ChannelResolver;
use PHPUnit\Framework\TestCase;

final class ChannelResolverTest extends TestCase
{
    public function testResolvesToTransparenciaWhenPortalUrlIsSet(): void
    {
        $body = (new PublicBody())
            ->setName('Ministerio de Cultura')
            ->setTransparencyPortalUrl('https://transparencia.sede.gob.es');

        $this->assertSame(
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL,
            (new ChannelResolver())->resolveTaskType($body)
        );
    }

    public function testResolvesToRegWhenPortalUrlIsMissing(): void
    {
        $body = (new PublicBody())->setName('Ayuntamiento de Cuenca');

        $this->assertSame(
            AgentTask::TYPE_SUBMIT_REQUEST_REG,
            (new ChannelResolver())->resolveTaskType($body)
        );
    }

    public function testBadgeLabels(): void
    {
        $resolver = new ChannelResolver();

        $portal = (new PublicBody())->setName('A')->setTransparencyPortalUrl('https://x.example');
        $reg = (new PublicBody())->setName('B');

        $this->assertSame(ChannelResolver::BADGE_PORTAL, $resolver->badgeLabel($portal));
        $this->assertSame(ChannelResolver::BADGE_REG, $resolver->badgeLabel($reg));
    }

    public function testDistinctChannelsForMixedSelection(): void
    {
        $resolver = new ChannelResolver();
        $bodies = [
            (new PublicBody())->setName('A')->setTransparencyPortalUrl('https://x.example'),
            (new PublicBody())->setName('B'),
            (new PublicBody())->setName('C')->setTransparencyPortalUrl('https://y.example'),
        ];

        $this->assertSame(
            [AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, AgentTask::TYPE_SUBMIT_REQUEST_REG],
            $resolver->distinctChannelsFor($bodies)
        );
    }
}
