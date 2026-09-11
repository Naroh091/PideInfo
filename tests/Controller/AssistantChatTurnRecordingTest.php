<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AssistantChatController;
use App\Entity\AccessRequest;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest as AssistantChatTurn;
use App\Service\AI\Chat\AssistantTurnStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit test for the turn recording woven into streamEvents: a turn sent with a
 * client turn id is opened in AssistantTurnStore before the agent runs and closed
 * with the replayable events (full reply, enriched decision, errors) before `done`,
 * so a browser whose SSE stream was cut can recover it. Collaborators are mocked;
 * the private generator is driven via reflection.
 */
class AssistantChatTurnRecordingTest extends TestCase
{
    private const THREAD_KEY = 'draft_chat_history';
    private const TURN_ID = 'turn-1234';

    private function makeController(AgentChatOrchestrator $streamer, AssistantTurnStore $store): AssistantChatController
    {
        $ref = new \ReflectionClass(AssistantChatController::class);
        $c = $ref->newInstanceWithoutConstructor();

        $ref->getProperty('streamer')->setValue($c, $streamer);
        $ref->getProperty('turnStore')->setValue($c, $store);
        $ref->getProperty('entityManager')->setValue($c, $this->createMock(EntityManagerInterface::class));
        $ref->getProperty('logger')->setValue($c, new NullLogger());

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

    /**
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    private function streamEvents(AssistantChatController $c, callable $onDecision, AccessRequest $ar, ?string $turnId): \Generator
    {
        $m = new \ReflectionMethod($c, 'streamEvents');

        return $m->invoke($c, $this->turn(), 'pide las facturas', $onDecision, false, $ar, null, $turnId, self::THREAD_KEY);
    }

    public function testTurnIsRecordedWithReplayableEventsBeforeDone(): void
    {
        $ar = new AccessRequest();
        $draft = ['title' => 'Solicitud', 'body_text' => 'Pido las facturas'];
        $streamer = $this->streamerYielding([
            ['step', ['message' => 'Buscando resoluciones…', 'tool' => 'search_resolutions']],
            ['chat_token', ['text' => 'Aquí tienes ']],
            ['chat_token', ['text' => 'el borrador.']],
            ['decision', ['action' => 'generate', 'draft' => $draft, 'plan' => []]],
        ]);

        $finished = false;
        $store = $this->createMock(AssistantTurnStore::class);
        $store->expects($this->once())->method('start')->with($ar, self::THREAD_KEY, self::TURN_ID);
        $store->expects($this->once())
            ->method('finish')
            ->with($ar, self::THREAD_KEY, self::TURN_ID, AssistantTurnStore::STATUS_DONE, [
                ['chat_token', ['text' => 'Aquí tienes el borrador.']],
                // The decision is recorded as sent: merged with what onDecision added.
                ['decision', ['action' => 'generate', 'draft' => $draft, 'plan' => [], 'previous' => ['title' => '']]],
            ])
            ->willReturnCallback(function () use (&$finished): void {
                $finished = true;
            });

        $onDecision = static fn (): array => ['previous' => ['title' => '']];
        $events = [];
        foreach ($this->streamEvents($this->makeController($streamer, $store), $onDecision, $ar, self::TURN_ID) as $tuple) {
            if ($tuple[0] === 'done') {
                $this->assertTrue($finished, 'The turn must be closed before `done` reaches the browser.');
            }
            $events[] = $tuple;
        }

        $this->assertSame(['step', 'chat_token', 'chat_token', 'decision', 'done'], array_column($events, 0));
    }

    public function testFailedTurnIsRecordedAsError(): void
    {
        $ar = new AccessRequest();
        $streamer = $this->createMock(AgentChatOrchestrator::class);
        $streamer->method('stream')->willReturnCallback(function (): \Generator {
            throw new \RuntimeException('boom');
            yield; // @phpstan-ignore deadCode.unreachable
        });

        $store = $this->createMock(AssistantTurnStore::class);
        $store->expects($this->once())
            ->method('finish')
            ->with($ar, self::THREAD_KEY, self::TURN_ID, AssistantTurnStore::STATUS_ERROR, [
                ['error', ['message' => 'Error inesperado: boom']],
            ]);

        iterator_to_array($this->streamEvents($this->makeController($streamer, $store), static fn () => null, $ar, self::TURN_ID), false);
    }

    public function testTurnWithoutIdIsNotRecorded(): void
    {
        $store = $this->createMock(AssistantTurnStore::class);
        $store->expects($this->never())->method('start');
        $store->expects($this->never())->method('finish');

        $streamer = $this->streamerYielding([
            ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]],
        ]);

        $events = iterator_to_array($this->streamEvents($this->makeController($streamer, $store), static fn () => null, new AccessRequest(), null), false);

        $this->assertSame(['decision', 'done'], array_column($events, 0));
    }

    public function testRecordingFailureNeverBreaksTheTurn(): void
    {
        $store = $this->createMock(AssistantTurnStore::class);
        $store->method('start')->willThrowException(new \RuntimeException('database unavailable'));
        // A turn whose start was never recorded is not closed either.
        $store->expects($this->never())->method('finish');

        $streamer = $this->streamerYielding([
            ['chat_token', ['text' => 'Hola']],
            ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]],
        ]);

        $events = iterator_to_array($this->streamEvents($this->makeController($streamer, $store), static fn () => null, new AccessRequest(), self::TURN_ID), false);

        $this->assertSame(['chat_token', 'decision', 'done'], array_column($events, 0));
    }
}
