<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the ai_reg_destinations pgvector store used for semantic search over
 * REG (Registro Electrónico Común) destinations. Mirrors the schema of
 * ai_documents / ai_resolutions (id UUID, metadata JSONB, halfvec(3072)). Adds an
 * HNSW index on the embedding column (cosine) plus btree indexes on the metadata
 * keys the retriever filters / dedupes by (regDestinationId, comunidad, provincia)
 * so the indexer can wipe-and-reinsert per destination idempotently.
 */
final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_reg_destinations pgvector store for semantic search over REG destinations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('ai_reg_destinations') IS NULL THEN
                CREATE TABLE ai_reg_destinations (
                    id UUID PRIMARY KEY,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding halfvec(3072)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_reg_destinations_reg_destination_id
                ON ai_reg_destinations ((metadata->>'regDestinationId'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_reg_destinations_comunidad
                ON ai_reg_destinations ((metadata->>'comunidad'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_reg_destinations_provincia
                ON ai_reg_destinations ((metadata->>'provincia'))
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_ai_reg_destinations_embedding_hnsw
                ON ai_reg_destinations USING hnsw (embedding halfvec_cosine_ops)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_reg_destinations') IS NOT NULL THEN DROP TABLE ai_reg_destinations; END IF; END $$");
    }
}
