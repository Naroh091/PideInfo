<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Service\AI\Agent\AgentDoctrineContext;
use PHPUnit\Framework\TestCase;

final class AgentDoctrineContextTest extends TestCase
{
    public function testDefaultsToEmpty(): void
    {
        $this->assertSame([], (new AgentDoctrineContext())->getPriorityOrganismIds());
    }

    public function testSetAndGet(): void
    {
        $context = new AgentDoctrineContext();
        $context->setPriorityOrganismIds(['a', 'b']);

        $this->assertSame(['a', 'b'], $context->getPriorityOrganismIds());
    }

    public function testResetClears(): void
    {
        $context = new AgentDoctrineContext();
        $context->setPriorityOrganismIds(['a']);
        $context->reset();

        $this->assertSame([], $context->getPriorityOrganismIds());
    }
}
