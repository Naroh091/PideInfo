<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_portal_notifications JSON column to access_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ADD COLUMN IF NOT EXISTS pending_portal_notifications JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP COLUMN IF EXISTS pending_portal_notifications');
    }
}
