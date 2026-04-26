<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426220836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata JSON column to access_request for cached AI artifacts (e.g. success_analysis).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('access_request');

        if (!$table->hasColumn('metadata')) {
            $this->addSql('ALTER TABLE access_request ADD COLUMN metadata JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('access_request');

        if ($table->hasColumn('metadata')) {
            $this->addSql('ALTER TABLE access_request DROP COLUMN metadata');
        }
    }
}
