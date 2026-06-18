<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `resolution.updated_at` (lifecycle-managed via #[ORM\PreUpdate]).
 *
 * Idempotent: the column is added only if missing and existing rows are
 * backfilled from `created_at` before the NOT NULL constraint is applied, so the
 * table can carry the same non-null contract as the entity. Re-running is a no-op.
 */
final class Version20260618120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add resolution.updated_at (backfilled from created_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('UPDATE resolution SET updated_at = created_at WHERE updated_at IS NULL');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS updated_at');
    }
}
