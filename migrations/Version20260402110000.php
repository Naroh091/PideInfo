<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add batch_job_id FK on resolution to track which GeminiBatchJob processed each resolution';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS batch_job_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_batch_job ON resolution (batch_job_id)');

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                ALTER TABLE resolution
                    ADD CONSTRAINT fk_resolution_batch_job
                    FOREIGN KEY (batch_job_id)
                    REFERENCES gemini_batch_job (id)
                    ON DELETE SET NULL;
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP CONSTRAINT IF EXISTS fk_resolution_batch_job');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS batch_job_id');
    }
}
