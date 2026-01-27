<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260127225031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE complaint_organism (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(100) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, complaint_form_url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, address LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE applicable_law ADD complaint_organism_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE applicable_law ADD CONSTRAINT FK_2AD25F0A684F2EF FOREIGN KEY (complaint_organism_id) REFERENCES complaint_organism (id)');
        $this->addSql('CREATE INDEX IDX_2AD25F0A684F2EF ON applicable_law (complaint_organism_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE applicable_law DROP FOREIGN KEY FK_2AD25F0A684F2EF');
        $this->addSql('DROP INDEX IDX_2AD25F0A684F2EF ON applicable_law');
        $this->addSql('ALTER TABLE applicable_law DROP complaint_organism_id');
        $this->addSql('DROP TABLE complaint_organism');
    }
}
