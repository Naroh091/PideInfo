<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260308120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subject, keywords, and claim_reason fields to resolution table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ADD subject VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD keywords JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE resolution ADD claim_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution DROP COLUMN subject');
        $this->addSql('ALTER TABLE resolution DROP COLUMN keywords');
        $this->addSql('ALTER TABLE resolution DROP COLUMN claim_reason');
    }
}
