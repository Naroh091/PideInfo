<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GIN indexes on resolution.limits and resolution.inadmission_causes for fast containment queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_limits_gin ON resolution USING GIN (limits jsonb_path_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_inadmission_causes_gin ON resolution USING GIN (inadmission_causes jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_limits_gin');
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_inadmission_causes_gin');
    }
}
