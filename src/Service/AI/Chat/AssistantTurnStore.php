<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Entity\AccessRequest;
use Doctrine\DBAL\Connection;

/**
 * Records the outcome of the latest assistant chat turn per conversation thread in
 * `ai_assistant_turn`, independently of the SSE connection that produced it.
 *
 * A turn can outlive its HTTP connection (a proxy timeout, a flaky mobile network, a
 * reload while the model is still working). The controller opens the record when the
 * turn starts and closes it with the replayable events (full reply, decision, error)
 * right before `done`. The browser polls it to recover a result it never received and
 * acknowledges delivery, so a reload does not apply the same result twice.
 *
 * One row per (access_request_id, thread_key): a new turn supersedes the previous one,
 * and every later write is pinned to its turn_id so a superseded turn that finishes
 * late can never overwrite the newer record.
 */
class AssistantTurnStore
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    /** Reported (never stored) for a `running` record whose worker cannot still be alive. */
    public const STATUS_LOST = 'lost';

    /** Client-generated turn ids (crypto.randomUUID() or a fallback). */
    public const TURN_ID_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

    /**
     * Past this age a `running` turn is reported as lost: the php-fpm `assistant` pool
     * kills the request at 900 s (docker/php-fpm.conf), so nothing can still finish it.
     * The extra minute absorbs clock skew between PHP and PostgreSQL.
     */
    private const STALE_AFTER_SECONDS = 960;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function start(AccessRequest $ar, string $threadKey, string $turnId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO ai_assistant_turn (access_request_id, thread_key, turn_id, status, events, started_at)
            VALUES (:arId, :threadKey, :turnId, :status, '[]'::jsonb, NOW())
            ON CONFLICT (access_request_id, thread_key)
            DO UPDATE SET turn_id = EXCLUDED.turn_id, status = EXCLUDED.status, events = '[]'::jsonb,
                          started_at = NOW(), finished_at = NULL, delivered_at = NULL
            SQL,
            [
                'arId'      => $ar->getId()->toRfc4122(),
                'threadKey' => $threadKey,
                'turnId'    => $turnId,
                'status'    => self::STATUS_RUNNING,
            ],
        );
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $events
     */
    public function finish(AccessRequest $ar, string $threadKey, string $turnId, string $status, array $events): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
            UPDATE ai_assistant_turn
               SET status = :status, events = :events::jsonb, finished_at = NOW()
             WHERE access_request_id = :arId AND thread_key = :threadKey AND turn_id = :turnId
            SQL,
            [
                'arId'      => $ar->getId()->toRfc4122(),
                'threadKey' => $threadKey,
                'turnId'    => $turnId,
                'status'    => $status,
                'events'    => json_encode($events, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ],
        );
    }

    /**
     * @return array{turnId: string, status: string, events: list<array{0: string, 1: array<string, mixed>}>, startedAt: string, finishedAt: ?string, deliveredAt: ?string}|null
     */
    public function find(AccessRequest $ar, string $threadKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT turn_id, status, events, started_at, finished_at, delivered_at
               FROM ai_assistant_turn
              WHERE access_request_id = :arId AND thread_key = :threadKey',
            ['arId' => $ar->getId()->toRfc4122(), 'threadKey' => $threadKey],
        );

        if ($row === false) {
            return null;
        }

        $startedAt = new \DateTimeImmutable((string) $row['started_at']);
        $status = (string) $row['status'];
        if ($status === self::STATUS_RUNNING && time() - $startedAt->getTimestamp() > self::STALE_AFTER_SECONDS) {
            $status = self::STATUS_LOST;
        }

        $events = json_decode((string) $row['events'], true);

        return [
            'turnId'      => (string) $row['turn_id'],
            'status'      => $status,
            'events'      => is_array($events) ? $events : [],
            'startedAt'   => $startedAt->format(\DateTimeInterface::ATOM),
            'finishedAt'  => self::formatTimestamp($row['finished_at']),
            'deliveredAt' => self::formatTimestamp($row['delivered_at']),
        ];
    }

    /** Returns false when the turn is unknown, superseded or already acknowledged. */
    public function markDelivered(AccessRequest $ar, string $threadKey, string $turnId): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
            UPDATE ai_assistant_turn
               SET delivered_at = NOW()
             WHERE access_request_id = :arId AND thread_key = :threadKey AND turn_id = :turnId
               AND delivered_at IS NULL
            SQL,
            [
                'arId'      => $ar->getId()->toRfc4122(),
                'threadKey' => $threadKey,
                'turnId'    => $turnId,
            ],
        ) > 0;
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
    }
}
