<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds document.custom_name: a user-defined display name that overrides the
 * derived "<TypeLabel> - <original>" filename (renombrado manual). Nullable;
 * null means "use the derived name".
 */
final class Version20260708120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document.custom_name for manual document renaming.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS custom_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS custom_name');
    }
}
