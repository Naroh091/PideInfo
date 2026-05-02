<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the ai_documents pgvector store used to pre-compute embeddings of every
 * uploaded Document. Mirrors the schema of ai_resolutions and ai_ctbg_criteria
 * (id UUID, metadata JSONB, halfvec(3072)). Adds an HNSW index on the embedding
 * column (cosine) and a btree on metadata->>'documentId' so the embeddings handler
 * can wipe-and-reinsert per Document idempotently.
 */
final class Version20260502130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_documents pgvector store for per-document precomputed embeddings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('ai_documents') IS NULL THEN
                CREATE TABLE ai_documents (
                    id UUID PRIMARY KEY,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding halfvec(3072)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_documents_document_id
                ON ai_documents ((metadata->>'documentId'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_documents_access_request_id
                ON ai_documents ((metadata->>'accessRequestId'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_documents_embedding_hnsw
                ON ai_documents USING hnsw (embedding halfvec_cosine_ops)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_documents') IS NOT NULL THEN DROP TABLE ai_documents; END IF; END $$");
    }
}
