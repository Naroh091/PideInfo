<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the ai_judgments pgvector store for semantic search over judgment texts and
 * doctrine. Mirrors ai_resolutions (id UUID, metadata JSONB, halfvec(3072), HNSW cosine).
 *
 * The btree on metadata->>'judgment_id' is what lets JudgmentVectorizer wipe-and-reinsert
 * per judgment idempotently — and that key is ALWAYS present, enforced by a unit test,
 * because the resolution store shipped a code path that forgot it and its vectors became
 * invisible to the retriever.
 */
final class Version20260713120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_judgments pgvector store for semantic search over judgments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('ai_judgments') IS NULL THEN
                CREATE TABLE ai_judgments (
                    id UUID PRIMARY KEY,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding halfvec(3072)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_judgments_judgment_id
                ON ai_judgments ((metadata->>'judgment_id'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_judgments_stance
                ON ai_judgments ((metadata->>'transparency_stance'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_judgments_embedding_hnsw
                ON ai_judgments USING hnsw (embedding halfvec_cosine_ops)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_judgments');
    }
}
