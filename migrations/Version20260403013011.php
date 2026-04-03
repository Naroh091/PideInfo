<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403013011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make resolution.resolution_date nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ALTER resolution_date DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ALTER resolution_date SET NOT NULL');
    }
}
