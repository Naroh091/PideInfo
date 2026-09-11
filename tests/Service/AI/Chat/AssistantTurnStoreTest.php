<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat;

use App\Entity\AccessRequest;
use App\Service\AI\Chat\AssistantTurnStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AssistantTurnStoreTest extends TestCase
{
    public function testFindReturnsNullWithoutRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $this->assertNull((new AssistantTurnStore($connection))->find(new AccessRequest(), 'draft_chat_history'));
    }

    public function testFindDecodesAFinishedTurn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'turn_id'      => 'turn-1234',
            'status'       => 'done',
            'events'       => '[["chat_token",{"text":"Hola"}],["decision",{"action":"reply","draft":null}]]',
            'started_at'   => '2026-09-11 10:00:00+00',
            'finished_at'  => '2026-09-11 10:06:30+00',
            'delivered_at' => null,
        ]);

        $turn = (new AssistantTurnStore($connection))->find(new AccessRequest(), 'draft_chat_history');

        $this->assertSame('turn-1234', $turn['turnId']);
        $this->assertSame('done', $turn['status']);
        $this->assertSame(
            [['chat_token', ['text' => 'Hola']], ['decision', ['action' => 'reply', 'draft' => null]]],
            $turn['events'],
        );
        $this->assertSame('2026-09-11T10:06:30+00:00', $turn['finishedAt']);
        $this->assertNull($turn['deliveredAt']);
    }

    public function testRunningTurnPastTheWorkerLimitIsReportedAsLost(): void
    {
        $this->assertSame(AssistantTurnStore::STATUS_LOST, $this->statusOfRunningTurnStartedSecondsAgo(3600));
    }

    public function testRecentRunningTurnStaysRunning(): void
    {
        $this->assertSame(AssistantTurnStore::STATUS_RUNNING, $this->statusOfRunningTurnStartedSecondsAgo(120));
    }

    public function testFinishIsPinnedToItsTurnId(): void
    {
        $ar = new AccessRequest();
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('turn_id = :turnId'),
                $this->callback(static fn (array $params): bool => $params['arId'] === $ar->getId()->toRfc4122()
                    && $params['threadKey'] === 'complaint_chat_history_complaint'
                    && $params['turnId'] === 'turn-1234'
                    && $params['status'] === AssistantTurnStore::STATUS_DONE
                    && json_decode($params['events'], true) === [['chat_token', ['text' => 'Listo']]]),
            )
            ->willReturn(1);

        (new AssistantTurnStore($connection))->finish(
            $ar,
            'complaint_chat_history_complaint',
            'turn-1234',
            AssistantTurnStore::STATUS_DONE,
            [['chat_token', ['text' => 'Listo']]],
        );
    }

    public function testMarkDeliveredReportsWhetherARowWasUpdated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1, 0);
        $store = new AssistantTurnStore($connection);
        $ar = new AccessRequest();

        $this->assertTrue($store->markDelivered($ar, 'draft_chat_history', 'turn-1234'));
        $this->assertFalse($store->markDelivered($ar, 'draft_chat_history', 'turn-1234'));
    }

    private function statusOfRunningTurnStartedSecondsAgo(int $seconds): string
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'turn_id'      => 'turn-1234',
            'status'       => AssistantTurnStore::STATUS_RUNNING,
            'events'       => '[]',
            'started_at'   => (new \DateTimeImmutable("-{$seconds} seconds"))->format('Y-m-d H:i:sP'),
            'finished_at'  => null,
            'delivered_at' => null,
        ]);

        return (new AssistantTurnStore($connection))->find(new AccessRequest(), 'draft_chat_history')['status'];
    }
}
