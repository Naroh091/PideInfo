<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column to complaint_organism and public_body tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_organism ADD COLUMN IF NOT EXISTS slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_complaint_organism_slug ON complaint_organism (slug)');

        $this->addSql('ALTER TABLE public_body ADD COLUMN IF NOT EXISTS slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_public_body_slug ON public_body (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_complaint_organism_slug');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS slug');

        $this->addSql('DROP INDEX IF EXISTS uniq_public_body_slug');
        $this->addSql('ALTER TABLE public_body DROP COLUMN IF EXISTS slug');
    }
}
