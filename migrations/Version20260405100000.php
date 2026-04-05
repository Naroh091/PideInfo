<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public_body_id FK to resolution table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS public_body_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_public_body ON resolution (public_body_id)');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE resolution ADD CONSTRAINT fk_resolution_public_body
                    FOREIGN KEY (public_body_id) REFERENCES public_body (id) ON DELETE SET NULL;
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$
        SQL);

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP CONSTRAINT IF EXISTS fk_resolution_public_body');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_public_body');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS public_body_id');
    }
}
