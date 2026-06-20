<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `closed_manually` to `hearing_process` so citizens can mark a hearing
 * period as completed without waiting for the end-date to pass.
 *
 * Fully idempotent: the ALTER is guarded with ADD COLUMN IF NOT EXISTS.
 */
final class Version20260620190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add closed_manually boolean to hearing_process (Ya he alegado feature).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE hearing_process
            ADD COLUMN IF NOT EXISTS closed_manually BOOLEAN NOT NULL DEFAULT FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hearing_process DROP COLUMN IF EXISTS closed_manually');
    }
}
