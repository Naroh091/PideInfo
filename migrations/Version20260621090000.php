<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `writing_preferences` JSONB column to the `user` table (Issue #90).
 *
 * Stores per-user AI writing preferences (tone, salutation, closing, free notes)
 * injected into the system prompt of the drafting assistant.
 *
 * Fully idempotent: the ALTER is guarded with ADD COLUMN IF NOT EXISTS.
 */
final class Version20260621090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add writing_preferences JSONB column to user table for AI drafting preferences (Issue #90).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
            ADD COLUMN IF NOT EXISTS writing_preferences JSONB DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS writing_preferences');
    }
}
