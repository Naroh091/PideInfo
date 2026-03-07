<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128120803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notify_time and notified_day_before_at fields to custom_deadline for day-before notifications and custom time support';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_deadline ADD notify_time TIME DEFAULT NULL, ADD notified_day_before_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_custom_deadline_notified_day_before ON custom_deadline (notified_day_before_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_custom_deadline_notified_day_before ON custom_deadline');
        $this->addSql('ALTER TABLE custom_deadline DROP notify_time, DROP notified_day_before_at');
    }
}
