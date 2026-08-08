<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\DTO\ChatMessage;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest;
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
            ['search_resolutions', 'read_request_documents', 'web_search', 'visit_url', 'scrape_url', 'find_law', 'edit_request'],
        );
    }

    public function testAnonymousToolsetExcludesEgressTools(): void
    {
        $o = $this->orchestratorWithToolDefs($this->sampleDefs());

        $names = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', true, false));

        $this->assertNotContains('web_search', $names);
        $this->assertNotContains('visit_url', $names);
        $this->assertNotContains('scrape_url', $names);
        $this->assertContains('search_resolutions', $names);
        $this->assertContains('find_law', $names);
    }

    public function testAuthenticatedToolsetKeepsEgressTools(): void
    {
        $o = $this->orchestratorWithToolDefs($this->sampleDefs());

        $names = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', false, false));

        $this->assertContains('web_search', $names);
        $this->assertContains('visit_url', $names);
        $this->assertContains('scrape_url', $names);
    }

    public function testEditToolWithheldUnlessTurnAllowsIt(): void
    {
        $o = $this->orchestratorWithToolDefs($this->sampleDefs());

        $without = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', false, false));
        $with = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', false, true));
        // Un turno anónimo nunca la recibe, aunque el flag llegara encendido.
        $anon = array_map(static fn ($d) => $d['function']['name'], $this->call($o, 'toolDefinitionsFor', true, true));

        $this->assertNotContains('edit_request', $without);
        $this->assertContains('edit_request', $with);
        $this->assertNotContains('edit_request', $anon);
    }

    public function testAnonymousPreambleOmitsEgressSection(): void
    {
        $o = $this->orchestratorWithToolDefs([]);

        $anon = $this->call($o, 'toolsPreamble', true, false);
        $auth = $this->call($o, 'toolsPreamble', false, false);

        // The egress sections describe these tools; anonymous must not mention them.
        $this->assertStringNotContainsString('### web_search', $anon);
        $this->assertStringNotContainsString('### visit_url', $anon);
        $this->assertStringContainsString('### web_search', $auth);
        // Shared sections survive in both.
        $this->assertStringContainsString('### search_resolutions', $anon);
        $this->assertStringContainsString('Protocolo obligatorio', $anon);
        // The doctrine protocol is scoped to generate/rewrite turns: searching
        // doctrine to answer a question must NOT imply drafting.
        $this->assertStringContainsString('NO te obliga a redactar', $anon);
    }

    public function testUserMessageIsAlwaysTheLastMessage(): void
    {
        $o = $this->orchestratorWithToolDefs([]);

        $req = new AssistantChatRequest(
            flow: 'request',
            entityId: '00000000-0000-0000-0000-000000000000',
            systemPrompt: 'prompt',
            userMessage: '¿se puede pedir esto?',
            history: [
                new ChatMessage(role: 'user', content: 'hola'),
                new ChatMessage(role: 'assistant', content: 'respuesta'),
            ],
            attachments: [],
            label: 'test',
        );

        $messages = $this->call($o, 'buildMessages', $req, true, false);

        $last = end($messages);
        $this->assertSame('user', $last['role']);
        $this->assertSame('¿se puede pedir esto?', $last['content']);
        // The synthetic "Gracias. Procede ahora…" tail must be gone: it buried
        // the user's real question under an order to proceed per system prompt.
        $this->assertFalse(
            method_exists(AgentChatOrchestrator::class, 'runDeterministicPreCalls'),
            'runDeterministicPreCalls must stay deleted: it appended a synthetic user turn after the real one',
        );
    }

    public function testToLlmHistoryMarksDraftActions(): void
    {
        $messages = AgentChatOrchestrator::toLlmHistory([
            ['role' => 'user', 'content' => 'hazme el borrador'],
            ['role' => 'assistant', 'content' => '✦ Borrador generado.', 'action' => 'generate'],
            ['role' => 'user', 'content' => 'más corto'],
            ['role' => 'assistant', 'content' => 'Hecho.', 'action' => 'rewrite'],
            ['role' => 'assistant', 'content' => 'Una respuesta.', 'action' => 'reply'],
            ['role' => 'assistant', 'content' => 'Turno antiguo sin action.'],
        ]);

        $this->assertStringContainsString('[En este turno generé el borrador', $messages[1]->content);
        $this->assertStringContainsString('[En este turno reescribí el borrador', $messages[3]->content);
        $this->assertSame('Una respuesta.', $messages[4]->content);
        $this->assertSame('Turno antiguo sin action.', $messages[5]->content);
        // User turns are never annotated.
        $this->assertSame('hazme el borrador', $messages[0]->content);
    }
}
