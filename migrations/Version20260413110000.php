<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop days_to_resolve column — value is now computed on the fly from claim_date and resolution_date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS days_to_resolve');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS days_to_resolve INT DEFAULT NULL');
    }
}
