<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Audit aid for truncated PDF extractions: records which backend
 * (poppler|smalot|plain) produced document.full_text.
 */
final class Version20261017000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document.full_text_backend (extraction provenance).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS full_text_backend VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS full_text_backend');
    }
}
