<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unified template of a multi-recipient draft batch ("plantilla común"): one
 * generic text with [[MARCADORES]] for the parts that vary per organism, from
 * which a version per recipient is generated. Keyed by the batch id
 * (`AccessRequest.metadata.draft_batch_id`) and the owner; written only by
 * DraftBatchTemplateStore (DBAL, every query filters by user_id).
 *
 * Fully idempotent: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20261018000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create draft_batch_template (unified template of a multi-recipient draft batch).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS draft_batch_template (
                batch_id      UUID         NOT NULL,
                user_id       UUID         NOT NULL,
                title         VARCHAR(255) NOT NULL DEFAULT '',
                expone        TEXT         NOT NULL DEFAULT '',
                solicita      TEXT         NOT NULL DEFAULT '',
                cited_sources JSONB        NOT NULL DEFAULT '[]',
                created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                PRIMARY KEY (batch_id, user_id),
                CONSTRAINT fk_draft_batch_template_user
                    FOREIGN KEY (user_id) REFERENCES "user"(id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_draft_batch_template_user ON draft_batch_template (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS draft_batch_template');
    }
}
