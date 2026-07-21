<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AssistantChatController;
use App\DTO\ChatMessage;
use App\Entity\AccessRequest;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest as AssistantChatTurn;
use App\Service\AI\Moderation\AnonymousModerationGuard;
use App\Service\AI\Moderation\ModerationContext;
use App\Service\AI\Moderation\ModerationStage;
use App\Service\AI\Moderation\ModerationVerdict;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Unit test for the anonymous input/output moderation woven into streamTurn:
 * blocks reconduct via a synthetic reply, discard the draft, and record a strike;
 * clean turns pass through untouched. Collaborators are mocked; the private
 * streamTurn is driven via reflection and its SSE output captured.
 */
class AssistantChatModerationTest extends TestCase
{
    private function makeController(
        AnonymousModerationGuard $guard,
        AgentChatOrchestrator $streamer,
    ): AssistantChatController {
        $ref = new \ReflectionClass(AssistantChatController::class);
        $c = $ref->newInstanceWithoutConstructor();

        $set = function (string $prop, mixed $value) use ($ref, $c): void {
            $p = $ref->getProperty($prop);
            $p->setValue($c, $value);
        };

        $set('moderationGuard', $guard);
        $set('streamer', $streamer);
        $set('entityManager', $this->createMock(EntityManagerInterface::class));

        // Real strike limiter over in-memory storage (the factory class is final).
        $factory = new RateLimiterFactory(
            ['id' => 'strikes_test', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $set('moderationStrikeLimiter', $factory);

        return $c;
    }

    private function streamerYielding(array $tuples): AgentChatOrchestrator
    {
        $streamer = $this->createMock(AgentChatOrchestrator::class);
        $streamer->method('stream')->willReturnCallback(function () use ($tuples): \Generator {
            yield from $tuples;
        });

        return $streamer;
    }

    /**
     * Drives the private streamEvents generator and returns its yielded tuples.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function events(AssistantChatController $c, AssistantChatTurn $turn, string $userMessage, callable $onDecision, AccessRequest $ar): array
    {
        $m = new \ReflectionMethod($c, 'streamEvents');

        return iterator_to_array($m->invoke($c, $turn, $userMessage, $onDecision, true, $ar, '203.0.113.9'), false);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function actions(array $events): array
    {
        return array_values(array_filter(array_map(
            static fn ($e) => $e[0] === 'decision' ? ($e[1]['action'] ?? null) : null,
            $events,
        )));
    }

    private function turn(): AssistantChatTurn
    {
        return new AssistantChatTurn(
            flow: 'request',
            entityId: 'e',
            systemPrompt: 's',
            userMessage: 'u',
            history: [],
            attachments: [],
            label: 'test',
        );
    }

    private function turnWithDraft(): AssistantChatTurn
    {
        return new AssistantChatTurn(
            flow: 'request',
            entityId: 'e',
            systemPrompt: 's',
            userMessage: 'u',
            history: [
                new ChatMessage('user', 'Quiero pedir unos expedientes'),
                new ChatMessage('assistant', 'He redactado una solicitud sobre los expedientes 12/2024.'),
            ],
            attachments: [],
            label: 'test',
            hasDraft: true,
        );
    }

    public function testBlockedInputReconductsAndSkipsStreamer(): void
    {
        $guard = $this->createMock(AnonymousModerationGuard::class);
        $guard->method('moderate')->willReturn(ModerationVerdict::block('off_scope'));

        // Streamer must NEVER run on a blocked input.
        $streamer = $this->createMock(AgentChatOrchestrator::class);
        $streamer->expects($this->never())->method('stream');

        $c = $this->makeController($guard, $streamer);
        $ar = new AccessRequest();

        $decisions = [];
        $events = $this->events($c, $this->turn(), 'escríbeme un poema', function (string $a, ?array $d, string $r) use (&$decisions) {
            $decisions[] = [$a, $d, $r];

            return null;
        }, $ar);

        $tokenText = implode('', array_map(static fn ($e) => $e[0] === 'chat_token' ? $e[1]['text'] : '', $events));
        $this->assertStringContainsString('Solo puedo ayudarte a redactar', $tokenText);
        $this->assertSame(['reply'], $this->actions($events));
        $this->assertSame('done', $events[array_key_last($events)][0]);
        // A strike was recorded on the draft.
        $this->assertCount(1, $ar->getMetadataValue('anonymous')['moderation'] ?? []);
        $this->assertSame('input', $ar->getMetadataValue('anonymous')['moderation'][0]['stage']);
        // onDecision was called once with a reply (persisting the reconduct).
        $this->assertSame('reply', $decisions[0][0]);
    }

    public function testCleanTurnPassesDraftThrough(): void
    {
        $guard = $this->createMock(AnonymousModerationGuard::class);
        $guard->method('moderate')->willReturn(ModerationVerdict::allow());

        $streamer = $this->streamerYielding([
            ['decision', ['action' => 'generate', 'draft' => ['title' => 'Solicitud', 'body_text' => 'Pido las facturas'], 'plan' => []]],
        ]);

        $c = $this->makeController($guard, $streamer);
        $ar = new AccessRequest();

        $seen = null;
        $events = $this->events($c, $this->turn(), 'pide las facturas', function (string $a, ?array $d, string $r) use (&$seen) {
            $seen = [$a, $d];

            return ['draft' => $d];
        }, $ar);

        $this->assertSame('generate', $seen[0]);
        $this->assertSame(['generate'], $this->actions($events));
        $this->assertArrayNotHasKey('moderation', $ar->getMetadataValue('anonymous') ?? []);
    }

    public function testBlockedOutputDiscardsDraftAndReconducts(): void
    {
        $guard = $this->createMock(AnonymousModerationGuard::class);
        // Input clean, output blocked.
        $guard->method('moderate')->willReturnCallback(
            fn (string $t, ModerationStage $s) => $s === ModerationStage::Output
                ? ModerationVerdict::block('harmful_content')
                : ModerationVerdict::allow(),
        );

        $streamer = $this->streamerYielding([
            ['decision', ['action' => 'generate', 'draft' => ['title' => 'x', 'body_text' => 'texto difamatorio'], 'plan' => []]],
        ]);

        $c = $this->makeController($guard, $streamer);
        $ar = new AccessRequest();

        $onDecisionActions = [];
        $events = $this->events($c, $this->turn(), 'redacta esto', function (string $a, ?array $d, string $r) use (&$onDecisionActions) {
            $onDecisionActions[] = $a;

            return $d !== null ? ['draft' => $d] : null;
        }, $ar);

        // Reconduct emitted; the offending draft is never sent as a generate decision.
        $tokenText = implode('', array_map(static fn ($e) => $e[0] === 'chat_token' ? $e[1]['text'] : '', $events));
        $this->assertStringContainsString('Solo puedo ayudarte a redactar', $tokenText);
        $this->assertSame(['reply'], $this->actions($events));
        $this->assertContains('reply', $onDecisionActions);
        $this->assertNotContains('generate', $onDecisionActions);
        $this->assertCount(1, $ar->getMetadataValue('anonymous')['moderation'] ?? []);
        $this->assertSame('output', $ar->getMetadataValue('anonymous')['moderation'][0]['stage']);
    }

    public function testInputModerationReceivesConversationContext(): void
    {
        $seen = null;
        $guard = $this->createMock(AnonymousModerationGuard::class);
        $guard->method('moderate')->willReturnCallback(
            function (string $t, ModerationStage $s, ?ModerationContext $ctx = null) use (&$seen) {
                if ($s === ModerationStage::Input) {
                    $seen = $ctx;
                }

                return ModerationVerdict::allow();
            },
        );

        $streamer = $this->streamerYielding([
            ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]],
        ]);

        $c = $this->makeController($guard, $streamer);
        $ar = new AccessRequest();

        $this->events($c, $this->turnWithDraft(), '¿Qué dice la LCSP sobre el expediente?', function (string $a, ?array $d, string $r) {
            return null;
        }, $ar);

        $this->assertInstanceOf(ModerationContext::class, $seen);
        $this->assertTrue($seen->hasDraft);
        $this->assertSame('He redactado una solicitud sobre los expedientes 12/2024.', $seen->lastAssistantMessage);
    }
}
