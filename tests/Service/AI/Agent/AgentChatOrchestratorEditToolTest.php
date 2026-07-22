<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Service\AI\Agent\AgentChatOrchestrator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La tool edit_request solo se ofrece al modelo cuando el turno lo permite
 * (chat de consulta sobre una solicitud en borrador, usuario autenticado):
 * ni definición ni preámbulo fuera de ese caso, igual que las egress tools
 * para anónimos.
 */
final class AgentChatOrchestratorEditToolTest extends KernelTestCase
{
    public function testEditToolOfferedOnlyWhenTurnAllowsIt(): void
    {
        self::bootKernel();
        $orchestrator = static::getContainer()->get(AgentChatOrchestrator::class);

        self::assertContains('edit_request', $this->toolNames($orchestrator, anonymous: false, canEditRequest: true));
        self::assertNotContains('edit_request', $this->toolNames($orchestrator, anonymous: false, canEditRequest: false));
        // Un turno anónimo nunca la recibe, aunque el flag llegara encendido.
        self::assertNotContains('edit_request', $this->toolNames($orchestrator, anonymous: true, canEditRequest: true));
    }

    public function testPreambleMentionsEditToolOnlyWhenOffered(): void
    {
        self::bootKernel();
        $orchestrator = static::getContainer()->get(AgentChatOrchestrator::class);

        $with = $this->preamble($orchestrator, anonymous: false, canEditRequest: true);
        $without = $this->preamble($orchestrator, anonymous: false, canEditRequest: false);

        self::assertStringContainsString('edit_request', $with);
        self::assertStringNotContainsString('edit_request', $without);
        // La regla dura de los artículos legales se mantiene en ambos casos.
        self::assertStringContainsString('no cites ningún artículo', $with);
        self::assertStringContainsString('no cites ningún artículo', $without);
    }

    /** @return list<string> */
    private function toolNames(AgentChatOrchestrator $orchestrator, bool $anonymous, bool $canEditRequest): array
    {
        $definitions = $this->invokePrivate($orchestrator, 'toolDefinitionsFor', [$anonymous, $canEditRequest]);

        return array_values(array_map(
            static fn (array $d): string => (string) ($d['function']['name'] ?? ''),
            $definitions,
        ));
    }

    private function preamble(AgentChatOrchestrator $orchestrator, bool $anonymous, bool $canEditRequest): string
    {
        return $this->invokePrivate($orchestrator, 'toolsPreamble', [$anonymous, $canEditRequest]);
    }

    /** @param list<mixed> $args */
    private function invokePrivate(AgentChatOrchestrator $orchestrator, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($orchestrator, $method);

        return $ref->invoke($orchestrator, ...$args);
    }
}
