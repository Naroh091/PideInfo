<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111232137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE applicable_law ADD response_deadline_value INT NOT NULL, ADD response_deadline_unit VARCHAR(20) NOT NULL, ADD extension_value INT NOT NULL, ADD extension_unit VARCHAR(20) NOT NULL, DROP response_deadline_days, DROP extension_days');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE applicable_law ADD response_deadline_days INT NOT NULL, ADD extension_days INT NOT NULL, DROP response_deadline_value, DROP response_deadline_unit, DROP extension_value, DROP extension_unit');
    }
}
