<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates `ai_assistant_turn`: the outcome of the latest assistant chat turn per
 * conversation thread, so a result survives the SSE connection that produced it
 * (see AssistantTurnStore and docs/architecture.md → "Resiliencia del stream SSE").
 *
 * Design:
 *  - One row per (access_request_id, thread_key); a new turn overwrites the row.
 *  - `thread_key` is the controller's history key (`draft_chat_history`,
 *    `complaint_chat_history_{mode}`, `consult_chat_history`).
 *  - `events` holds the replayable SSE tuples (full reply, decision, error).
 *
 * Fully idempotent: CREATE TABLE IF NOT EXISTS.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_assistant_turn to recover assistant chat turns after an SSE disconnect.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ai_assistant_turn (
                access_request_id UUID         NOT NULL,
                thread_key        VARCHAR(100) NOT NULL,
                turn_id           VARCHAR(64)  NOT NULL,
                status            VARCHAR(16)  NOT NULL,
                events            JSONB        NOT NULL DEFAULT '[]',
                started_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                finished_at       TIMESTAMPTZ  NULL,
                delivered_at      TIMESTAMPTZ  NULL,
                CONSTRAINT pk_ai_assistant_turn PRIMARY KEY (access_request_id, thread_key),
                CONSTRAINT fk_ai_assistant_turn_access_request
                    FOREIGN KEY (access_request_id) REFERENCES access_request(id) ON DELETE CASCADE
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_assistant_turn');
    }
}
