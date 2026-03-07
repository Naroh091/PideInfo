<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260210120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alternative_references JSON column to access_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ADD alternative_references JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP alternative_references');
    }
}
