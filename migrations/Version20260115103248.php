<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115103248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request ADD third_party_status VARCHAR(50) NOT NULL DEFAULT \'none\', ADD third_party_allegations_started_at DATE DEFAULT NULL, ADD third_party_allegations_deadline_at DATE DEFAULT NULL, ADD deadline_suspended_at DATE DEFAULT NULL, ADD suspended_days_remaining INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request DROP third_party_status, DROP third_party_allegations_started_at, DROP third_party_allegations_deadline_at, DROP deadline_suspended_at, DROP suspended_days_remaining');
    }
}
