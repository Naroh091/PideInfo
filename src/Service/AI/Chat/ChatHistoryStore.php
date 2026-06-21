<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\DTO\ChatMessage;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Service\AI\Agent\AgentChatOrchestrator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Persists per-user, per-thread chat history in the `ai_chat_messages` table.
 *
 * Security invariant: every read and write includes `AND user_id = :userId` so
 * a user can never access another user's history, even with a known thread ID.
 *
 * Thread ID convention:
 *   request:{uuid}             — access-request draft chat
 *   complaint:{uuid}:{mode}    — complaint / alegation draft chat
 *
 * Fallback: when the dedicated table has no entry yet, load() reads the legacy
 * history from AccessRequest::metadata so existing sessions migrate seamlessly.
 * After the first save() the metadata key is the source of truth no longer.
 */
final class ChatHistoryStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    // ── Thread ID helpers ────────────────────────────────────────────────────

    public static function threadIdForRequest(AccessRequest $ar): string
    {
        return 'request:' . $ar->getId()->toRfc4122();
    }

    public static function threadIdForComplaint(AccessRequest $ar, string $mode): string
    {
        return 'complaint:' . $ar->getId()->toRfc4122() . ':' . $mode;
    }

    /**
     * Maps the legacy metadata key used by the controller to a thread ID.
     * Kept so the controller change is minimal.
     */
    public static function threadIdFromMetadataKey(AccessRequest $ar, string $metadataKey): string
    {
        if ($metadataKey === 'draft_chat_history') {
            return self::threadIdForRequest($ar);
        }
        // complaint_chat_history_{mode} → complaint:{uuid}:{mode}
        $mode = str_replace('complaint_chat_history_', '', $metadataKey);
        return self::threadIdForComplaint($ar, $mode);
    }

    // ── Core API ─────────────────────────────────────────────────────────────

    /**
     * Loads the full turn array for a thread. Returns [] if none found.
     * Falls back to AccessRequest::metadata when the table has no entry yet.
     *
     * @return list<array<string, mixed>>
     */
    public function load(string $threadId, User $user, ?AccessRequest $ar = null, ?string $metadataKey = null): array
    {
        $row = $this->connection->fetchOne(
            'SELECT turns FROM ai_chat_messages WHERE user_id = :userId AND thread_id = :threadId',
            ['userId' => $user->getId()->toRfc4122(), 'threadId' => $threadId],
        );

        if ($row !== false) {
            return json_decode($row, true) ?? [];
        }

        // Fallback: read legacy metadata so existing sessions continue to work.
        if ($ar !== null && $metadataKey !== null) {
            $legacy = $ar->getMetadataValue($metadataKey);
            return is_array($legacy) ? $legacy : [];
        }

        return [];
    }

    /**
     * Replaces the full turn array for a thread (UPSERT).
     * Always scoped to the given user — never writes without user_id.
     *
     * @param list<array<string, mixed>> $turns
     */
    public function save(string $threadId, User $user, array $turns): void
    {
        $this->assertOwnership($threadId, $user);

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO ai_chat_messages (user_id, thread_id, turns, updated_at)
            VALUES (:userId, :threadId, :turns::jsonb, NOW())
            ON CONFLICT (user_id, thread_id)
            DO UPDATE SET turns = :turns::jsonb, updated_at = NOW()
            SQL,
            [
                'userId'   => $user->getId()->toRfc4122(),
                'threadId' => $threadId,
                'turns'    => json_encode($turns, JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    /**
     * Appends new turns to the existing history, caps at $maxTurns, and saves.
     *
     * @param list<array<string, mixed>> $newTurns
     */
    public function append(
        string $threadId,
        User $user,
        array $newTurns,
        int $maxTurns = 60,
        ?AccessRequest $ar = null,
        ?string $metadataKey = null,
    ): void {
        $current = $this->load($threadId, $user, $ar, $metadataKey);
        foreach ($newTurns as $turn) {
            $current[] = $turn;
        }
        if (count($current) > $maxTurns) {
            $current = array_slice($current, -$maxTurns);
        }
        $this->save($threadId, $user, $current);
    }

    /**
     * Returns the last $window turns as ChatMessage DTOs for the LLM.
     *
     * @return list<ChatMessage>
     */
    public function loadForLlm(
        string $threadId,
        User $user,
        int $window = 12,
        ?AccessRequest $ar = null,
        ?string $metadataKey = null,
    ): array {
        $all = $this->load($threadId, $user, $ar, $metadataKey);
        $recent = array_slice($all, -$window);
        return AgentChatOrchestrator::toLlmHistory($recent);
    }

    // ── Security ─────────────────────────────────────────────────────────────

    /**
     * Guards against thread ID manipulation: if the table already has a row
     * for this thread ID, verify it belongs to the given user.
     *
     * For new threads (no existing row) this is a no-op — the INSERT will
     * bind the thread to the user on first write.
     */
    private function assertOwnership(string $threadId, User $user): void
    {
        $existingUserId = $this->connection->fetchOne(
            'SELECT user_id FROM ai_chat_messages WHERE thread_id = :threadId LIMIT 1',
            ['threadId' => $threadId],
        );

        if ($existingUserId !== false && $existingUserId !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException(
                'Thread ID belongs to a different user — write blocked.',
            );
        }
    }
}
