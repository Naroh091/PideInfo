<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Batch;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Persists the unified template of a draft batch in `draft_batch_template`.
 *
 * Security invariant (same as ChatHistoryStore): every read and write includes
 * `user_id = :userId`, so a known batch id never exposes another user's template.
 */
class DraftBatchTemplateStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** Returns the stored template, or an empty one if the batch has none yet. */
    public function find(string $batchId, User $user): DraftBatchTemplate
    {
        $row = $this->connection->fetchAssociative(
            'SELECT title, expone, solicita, cited_sources, updated_at
               FROM draft_batch_template
              WHERE batch_id = :batchId AND user_id = :userId',
            ['batchId' => $batchId, 'userId' => $user->getId()->toRfc4122()],
        );

        if ($row === false) {
            return new DraftBatchTemplate($batchId);
        }

        $sources = json_decode((string) $row['cited_sources'], true);

        return new DraftBatchTemplate(
            batchId: $batchId,
            title: (string) $row['title'],
            expone: (string) $row['expone'],
            solicita: (string) $row['solicita'],
            sources: is_array($sources) ? array_values($sources) : [],
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * Upserts the template text. `$sources` null keeps the stored citations
     * (manual autosave); an array replaces them (assistant generate/rewrite).
     *
     * @param list<array<string, mixed>>|null $sources
     */
    public function save(string $batchId, User $user, string $title, string $expone, string $solicita, ?array $sources = null): \DateTimeImmutable
    {
        $params = [
            'batchId'  => $batchId,
            'userId'   => $user->getId()->toRfc4122(),
            'title'    => mb_substr($title, 0, 255),
            'expone'   => mb_substr($expone, 0, 4000),
            'solicita' => mb_substr($solicita, 0, 4000),
            'sources'  => json_encode($sources ?? [], JSON_UNESCAPED_UNICODE),
        ];

        $onConflictSources = $sources === null ? '' : ', cited_sources = EXCLUDED.cited_sources';

        $updatedAt = $this->connection->fetchOne(
            <<<SQL
            INSERT INTO draft_batch_template (batch_id, user_id, title, expone, solicita, cited_sources, created_at, updated_at)
            VALUES (:batchId, :userId, :title, :expone, :solicita, :sources::jsonb, NOW(), NOW())
            ON CONFLICT (batch_id, user_id)
            DO UPDATE SET title = EXCLUDED.title, expone = EXCLUDED.expone, solicita = EXCLUDED.solicita,
                          updated_at = NOW(){$onConflictSources}
            RETURNING updated_at
            SQL,
            $params,
        );

        return new \DateTimeImmutable((string) $updatedAt);
    }
}
