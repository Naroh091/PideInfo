<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407171140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content column to applicable_law for storing law text in markdown';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE applicable_law ADD COLUMN IF NOT EXISTS content TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE applicable_law DROP COLUMN IF EXISTS content');
    }
}
