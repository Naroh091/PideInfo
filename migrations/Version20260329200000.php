<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_active field to user table, default false for beta closed access';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT false');

        // Activate all existing users (they were already using the system)
        $this->addSql('UPDATE "user" SET is_active = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS is_active');
    }
}
