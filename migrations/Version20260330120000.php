<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename email_metadata to source_metadata and add content_hash to document';
    }

    public function up(Schema $schema): void
    {
        // Rename email_metadata → source_metadata (idempotent)
        $this->addSql('
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = \'document\' AND column_name = \'email_metadata\'
                ) THEN
                    ALTER TABLE document RENAME COLUMN email_metadata TO source_metadata;
                END IF;
            END $$
        ');

        // Add content_hash column (idempotent)
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) DEFAULT NULL');

        // Index for cross-source deduplication
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_document_dedup ON document (uploaded_by_id, content_hash) WHERE content_hash IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_document_dedup');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS content_hash');
        $this->addSql('
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = \'document\' AND column_name = \'source_metadata\'
                ) THEN
                    ALTER TABLE document RENAME COLUMN source_metadata TO email_metadata;
                END IF;
            END $$
        ');
    }
}
