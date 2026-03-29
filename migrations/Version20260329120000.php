<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add virtual_email to user, source_type and email_metadata to document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS virtual_email VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_virtual_email ON "user" (virtual_email)');

        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS source_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS email_metadata JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_user_virtual_email');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS virtual_email');

        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS source_type');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS email_metadata');
    }
}
