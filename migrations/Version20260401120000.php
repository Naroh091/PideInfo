<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add keypoints and complaint_organism_id to resolution table, link existing resolutions to CTBG';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS keypoints JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS complaint_organism_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_complaint_organism ON resolution (complaint_organism_id)');

        // Link all existing resolutions to the CTBG organism
        $this->addSql(<<<'SQL'
            UPDATE resolution
            SET complaint_organism_id = (SELECT id FROM complaint_organism WHERE short_name = 'CTBG' LIMIT 1)
            WHERE complaint_organism_id IS NULL
        SQL);

        // Add FK constraint idempotently
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                ALTER TABLE resolution
                    ADD CONSTRAINT fk_resolution_complaint_organism
                    FOREIGN KEY (complaint_organism_id) REFERENCES complaint_organism(id);
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        $this->addSql("COMMENT ON COLUMN resolution.keypoints IS '(DC2Type:json)'");
        $this->addSql("COMMENT ON COLUMN resolution.complaint_organism_id IS '(DC2Type:uuid)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP CONSTRAINT IF EXISTS fk_resolution_complaint_organism');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_complaint_organism');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS complaint_organism_id');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS keypoints');
    }
}
