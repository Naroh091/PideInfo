<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\AgentTask;
use App\Entity\PublicBody;
use App\Service\Submission\ChannelResolver;
use PHPUnit\Framework\TestCase;

final class ChannelResolverTest extends TestCase
{
    public function testResolvesToTransparenciaWhenPortalAmbIdIsSet(): void
    {
        $body = (new PublicBody())
            ->setName('Ministerio de Cultura')
            ->setTransparencyPortalAmbId(101509);

        $this->assertSame(
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL,
            (new ChannelResolver())->resolveTaskType($body)
        );
    }

    public function testResolvesToRegWhenPortalAmbIdIsMissing(): void
    {
        $body = (new PublicBody())->setName('Ayuntamiento de Cuenca');

        $this->assertSame(
            AgentTask::TYPE_SUBMIT_REQUEST_REG,
            (new ChannelResolver())->resolveTaskType($body)
        );
    }

    /**
     * Regression: a transparencyPortalUrl alone does NOT make a body
     * submittable through the AGE wizard — the agent builds the formulario
     * URL from the numeric idAmb, so without transparencyPortalAmbId the
     * body must fall back to REG instead of a dead Portal route.
     */
    public function testResolvesToRegWhenPortalUrlSetButAmbIdMissing(): void
    {
        $body = (new PublicBody())
            ->setName('Generalitat Valenciana')
            ->setTransparencyPortalUrl('https://gvaoberta.gva.es');

        $this->assertSame(
            AgentTask::TYPE_SUBMIT_REQUEST_REG,
            (new ChannelResolver())->resolveTaskType($body)
        );
    }

    public function testBadgeLabels(): void
    {
        $resolver = new ChannelResolver();

        $portal = (new PublicBody())->setName('A')->setTransparencyPortalAmbId(101509);
        $reg = (new PublicBody())->setName('B');

        $this->assertSame(ChannelResolver::BADGE_PORTAL, $resolver->badgeLabel($portal));
        $this->assertSame(ChannelResolver::BADGE_REG, $resolver->badgeLabel($reg));
    }

    public function testDistinctChannelsForMixedSelection(): void
    {
        $resolver = new ChannelResolver();
        $bodies = [
            (new PublicBody())->setName('A')->setTransparencyPortalAmbId(101509),
            (new PublicBody())->setName('B'),
            (new PublicBody())->setName('C')->setTransparencyPortalAmbId(101510),
        ];

        $this->assertSame(
            [AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, AgentTask::TYPE_SUBMIT_REQUEST_REG],
            $resolver->distinctChannelsFor($bodies)
        );
    }
}
