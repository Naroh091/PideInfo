<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115104329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request ADD redirected_at DATE DEFAULT NULL, ADD original_public_body_id BINARY(16) DEFAULT NULL, CHANGE third_party_status third_party_status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A3BA76F8A FOREIGN KEY (original_public_body_id) REFERENCES public_body (id)');
        $this->addSql('CREATE INDEX IDX_F3B2558A3BA76F8A ON access_request (original_public_body_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request DROP FOREIGN KEY FK_F3B2558A3BA76F8A');
        $this->addSql('DROP INDEX IDX_F3B2558A3BA76F8A ON access_request');
        $this->addSql('ALTER TABLE access_request DROP redirected_at, DROP original_public_body_id, CHANGE third_party_status third_party_status VARCHAR(50) DEFAULT \'none\' NOT NULL');
    }
}
