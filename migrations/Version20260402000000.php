<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new Resolution fields: scope, autonomousCommunity, claimDate, entryYear, challengedActs, councilCriteria, entityType, pdfStoragePath, daysToResolve, sourceMetadata. Add GIN indexes on JSON columns.';
    }

    public function up(Schema $schema): void
    {
        // New scalar columns
        $this->addSql("ALTER TABLE resolution ADD COLUMN IF NOT EXISTS scope VARCHAR(20) NOT NULL DEFAULT 'national'");
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS claim_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS entry_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS entity_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS pdf_storage_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS days_to_resolve INT DEFAULT NULL');

        // New JSON columns
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS challenged_acts JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS council_criteria JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS source_metadata JSONB DEFAULT NULL');

        // FK to autonomous_community
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS autonomous_community_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_ac ON resolution (autonomous_community_id)');

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                ALTER TABLE resolution
                    ADD CONSTRAINT fk_resolution_ac
                    FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community(id);
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // Regular indexes
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_scope ON resolution (scope)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_source ON resolution (source)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_entry_year ON resolution (entry_year)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_entity_type ON resolution (entity_type)');

        // Cast existing JSON columns to JSONB for GIN indexing (idempotent: no-op if already JSONB)
        $this->addSql("DO $$ BEGIN ALTER TABLE resolution ALTER COLUMN topics TYPE JSONB USING topics::JSONB; EXCEPTION WHEN others THEN NULL; END $$");
        $this->addSql("DO $$ BEGIN ALTER TABLE resolution ALTER COLUMN keywords TYPE JSONB USING keywords::JSONB; EXCEPTION WHEN others THEN NULL; END $$");
        $this->addSql("DO $$ BEGIN ALTER TABLE resolution ALTER COLUMN keypoints TYPE JSONB USING keypoints::JSONB; EXCEPTION WHEN others THEN NULL; END $$");

        // GIN indexes on JSONB arrays for containment queries
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_challenged_acts ON resolution USING GIN (challenged_acts jsonb_path_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_council_criteria ON resolution USING GIN (council_criteria jsonb_path_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_topics_gin ON resolution USING GIN (topics jsonb_path_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_keywords_gin ON resolution USING GIN (keywords jsonb_path_ops)');

        // Remove duplicates before adding unique constraint (keep the most recent)
        $this->addSql(<<<'SQL'
            DELETE FROM resolution
            WHERE id NOT IN (
                SELECT DISTINCT ON (reference_number, source) id
                FROM resolution
                ORDER BY reference_number, source, created_at DESC
            )
        SQL);

        // Unique constraint on reference_number + source
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                ALTER TABLE resolution
                    ADD CONSTRAINT uniq_reference_source UNIQUE (reference_number, source);
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        // Set existing rows to national scope (idempotent)
        $this->addSql("UPDATE resolution SET scope = 'national' WHERE scope IS NULL OR scope = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP CONSTRAINT IF EXISTS uniq_reference_source');
        $this->addSql('ALTER TABLE resolution DROP CONSTRAINT IF EXISTS fk_resolution_ac');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_ac');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_scope');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_source');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_entry_year');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_entity_type');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_challenged_acts');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_council_criteria');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_topics_gin');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_keywords_gin');

        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS scope');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS autonomous_community_id');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS claim_date');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS entry_year');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS challenged_acts');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS council_criteria');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS entity_type');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS pdf_storage_path');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS days_to_resolve');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS source_metadata');
    }
}
