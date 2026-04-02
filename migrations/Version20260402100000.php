<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gemini_batch_job table for tracking Gemini Batch API jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gemini_batch_job (
                id UUID NOT NULL,
                batch_name VARCHAR(255) NOT NULL,
                task VARCHAR(50) NOT NULL,
                model VARCHAR(100) NOT NULL,
                state VARCHAR(50) NOT NULL DEFAULT 'pending',
                resolution_count INT NOT NULL DEFAULT 0,
                source_filter VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                imported_count INT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gemini_batch_job_state ON gemini_batch_job (state)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gemini_batch_job_created ON gemini_batch_job (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gemini_batch_job');
    }
}
