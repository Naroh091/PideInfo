<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen resolution string columns to TEXT to prevent truncation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ALTER COLUMN source_url TYPE TEXT');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN public_body_name TYPE TEXT');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN subject TYPE TEXT');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN claim_reason TYPE TEXT');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN pdf_storage_path TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resolution ALTER COLUMN source_url TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN public_body_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN subject TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN claim_reason TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE resolution ALTER COLUMN pdf_storage_path TYPE VARCHAR(500)');
    }
}
