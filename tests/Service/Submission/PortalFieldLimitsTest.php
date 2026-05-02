<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\AgentTask;
use App\Service\Submission\PortalFieldLimits;
use PHPUnit\Framework\TestCase;

final class PortalFieldLimitsTest extends TestCase
{
    public function testTransparenciaAgeLimitsMatchDiscovery(): void
    {
        $limits = PortalFieldLimits::forChannel(AgentTask::TYPE_SUBMIT_REQUEST_PORTAL);

        $this->assertSame(255, $limits['title']);
        $this->assertSame(3000, $limits['description']);
        $this->assertSame(50, $limits['address_line']);
    }

    public function testRegLimitsAreEmptyUntilDiscovery(): void
    {
        $this->assertSame([], PortalFieldLimits::forChannel(AgentTask::TYPE_SUBMIT_REQUEST_REG));
    }

    public function testUnknownChannelReturnsEmpty(): void
    {
        $this->assertSame([], PortalFieldLimits::forChannel('not_a_channel'));
    }

    public function testIntersectReturnsTheMostRestrictivePerField(): void
    {
        $combined = PortalFieldLimits::intersect([
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL,
            AgentTask::TYPE_SUBMIT_REQUEST_REG,
        ]);

        // REG declares none, so the AGE numbers stand.
        $this->assertSame(255, $combined['title']);
        $this->assertSame(3000, $combined['description']);
        $this->assertSame(50, $combined['address_line']);
    }

    public function testIntersectWithNoChannelsIsEmpty(): void
    {
        $this->assertSame([], PortalFieldLimits::intersect([]));
    }
}
