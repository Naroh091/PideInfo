<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add criterion table for interpretive criteria (criterios interpretativos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS criterion (
                id UUID NOT NULL,
                complaint_organism_id UUID DEFAULT NULL,
                autonomous_community_id UUID DEFAULT NULL,
                reference_number VARCHAR(100) NOT NULL,
                year INT DEFAULT NULL,
                topic TEXT DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                full_text TEXT NOT NULL,
                keypoints JSONB DEFAULT NULL,
                source VARCHAR(50) DEFAULT 'CTBG' NOT NULL,
                source_url TEXT DEFAULT NULL,
                pdf_storage_path TEXT DEFAULT NULL,
                scope VARCHAR(20) DEFAULT 'national' NOT NULL,
                published_at DATE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN criterion.id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN criterion.complaint_organism_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN criterion.autonomous_community_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN criterion.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN criterion.published_at IS '(DC2Type:date_immutable)'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_criterion_source ON criterion (source)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_criterion_year ON criterion (year)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_criterion_scope ON criterion (scope)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_criterion_complaint_organism ON criterion (complaint_organism_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_criterion_autonomous_community ON criterion (autonomous_community_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_criterion_reference_source ON criterion (reference_number, source)
        SQL);

        // Defer FK creation so the migration is idempotent on schemas that
        // already have a partial table (mirrors the pattern used by other
        // recent migrations in this repo).
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_criterion_complaint_organism'
                ) THEN
                    ALTER TABLE criterion
                    ADD CONSTRAINT fk_criterion_complaint_organism
                    FOREIGN KEY (complaint_organism_id) REFERENCES complaint_organism (id)
                    ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_criterion_autonomous_community'
                ) THEN
                    ALTER TABLE criterion
                    ADD CONSTRAINT fk_criterion_autonomous_community
                    FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community (id)
                    ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS criterion');
    }
}
