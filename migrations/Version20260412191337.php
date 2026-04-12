<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412191337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add info_request_date, limits and inadmission_causes columns to resolution';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS info_request_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS limits JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD COLUMN IF NOT EXISTS inadmission_causes JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS info_request_date');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS limits');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS inadmission_causes');
    }
}
