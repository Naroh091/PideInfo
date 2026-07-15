<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Service\AI\Agent\AgentChatOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Focused unit test for the anonymous toolset restriction. Builds the orchestrator
 * without its (large) constructor and drives the two private helpers via reflection.
 */
class AgentChatOrchestratorToolsetTest extends TestCase
{
    private function orchestratorWithToolDefs(array $defs): AgentChatOrchestrator
    {
        $ref = new \ReflectionClass(AgentChatOrchestrator::class);
        $o = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('toolDefinitions');
        $prop->setValue($o, $defs);

        return $o;
    }

    private function call(AgentChatOrchestrator $o, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($o, $method);

        return $m->invoke($o, ...$args);
    }

    private function sampleDefs(): array
    {
        return array_map(
            static fn (string $name) => ['type' => 'function', 'function' => ['name' => $name, 'description' => '', 'parameters' => []]],
            ['search_resolutions', 'read_request_documents', 'web_search', 'visit_url', 'scrape_url', 'find_law'],
        );
    }

    public function testAnonymousToolsetExcludesEgressTools(): void
    {
        $o = $this->orchestratorWithToolDefs($this->sampleDefs());

        $names = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', true));

        $this->assertNotContains('web_search', $names);
        $this->assertNotContains('visit_url', $names);
        $this->assertNotContains('scrape_url', $names);
        $this->assertContains('search_resolutions', $names);
        $this->assertContains('find_law', $names);
    }

    public function testAuthenticatedToolsetKeepsEgressTools(): void
    {
        $o = $this->orchestratorWithToolDefs($this->sampleDefs());

        $names = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', false));

        $this->assertContains('web_search', $names);
        $this->assertContains('visit_url', $names);
        $this->assertContains('scrape_url', $names);
    }

    public function testAnonymousPreambleOmitsEgressSection(): void
    {
        $o = $this->orchestratorWithToolDefs([]);

        $anon = $this->call($o, 'toolsPreamble', true);
        $auth = $this->call($o, 'toolsPreamble', false);

        // The egress sections describe these tools; anonymous must not mention them.
        $this->assertStringNotContainsString('### web_search', $anon);
        $this->assertStringNotContainsString('### visit_url', $anon);
        $this->assertStringContainsString('### web_search', $auth);
        // Shared sections survive in both.
        $this->assertStringContainsString('### search_resolutions', $anon);
        $this->assertStringContainsString('Protocolo obligatorio', $anon);
    }
}
