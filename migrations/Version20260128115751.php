<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128115751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_deadline table for user-defined reminders on access requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_deadline (id BINARY(16) NOT NULL, deadline_at DATE NOT NULL, description LONGTEXT NOT NULL, notified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, access_request_id BINARY(16) NOT NULL, INDEX IDX_5295939668402024 (access_request_id), INDEX idx_custom_deadline_request_date (access_request_id, deadline_at), INDEX idx_custom_deadline_notified (notified_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE custom_deadline ADD CONSTRAINT FK_5295939668402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_deadline DROP FOREIGN KEY FK_5295939668402024');
        $this->addSql('DROP TABLE custom_deadline');
    }
}
