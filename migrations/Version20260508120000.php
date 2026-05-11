<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CTBG batched-reconciliation support.
 *
 * 1. Adds expediente metadata to access_request_complaint
 *    (estado, titulo, fecha_apertura, fecha_cierre).
 *
 * 2. Adds external_ids JSONB to access_request_complaint as the historical
 *    log of every external reference the complaint has ever had. The
 *    "current" externalId column stays as the canonical authority; new IDs
 *    discovered via promoteExternalId are appended here so a late upload
 *    using an old ref still resolves.
 *
 * 3. Adds a partial composite index on document(uploaded_by_id, content_hash)
 *    restricted to source_type='portal' (the proxy for "consejo_ctbg-style
 *    uploads") so the hash-fallback lookup in AgentWebhookProcessor stays
 *    cheap as the documents table grows.
 *
 * Idempotent: every DDL is guarded with IF [NOT] EXISTS.
 */
final class Version20260508120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CTBG batched reconciliation: expediente metadata + external_ids history + partial hash index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request_complaint
                ADD COLUMN IF NOT EXISTS expediente_estado VARCHAR(50),
                ADD COLUMN IF NOT EXISTS expediente_titulo TEXT,
                ADD COLUMN IF NOT EXISTS fecha_apertura DATE,
                ADD COLUMN IF NOT EXISTS fecha_cierre DATE,
                ADD COLUMN IF NOT EXISTS external_ids JSONB DEFAULT '[]'::jsonb NOT NULL
        SQL);

        // Backfill external_ids from the current externalId so the new lookup
        // path resolves complaints created before this migration.
        $this->addSql(<<<'SQL'
            UPDATE access_request_complaint
            SET external_ids = jsonb_build_array(external_id)
            WHERE external_id IS NOT NULL AND external_ids = '[]'::jsonb
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_complaint_external_ids
                ON access_request_complaint USING GIN (external_ids)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_document_hash_user_portal
                ON document (uploaded_by_id, content_hash)
                WHERE source_type = 'portal'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_document_hash_user_portal');
        $this->addSql('DROP INDEX IF EXISTS idx_complaint_external_ids');
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request_complaint
                DROP COLUMN IF EXISTS external_ids,
                DROP COLUMN IF EXISTS fecha_cierre,
                DROP COLUMN IF EXISTS fecha_apertura,
                DROP COLUMN IF EXISTS expediente_titulo,
                DROP COLUMN IF EXISTS expediente_estado
        SQL);
    }
}
