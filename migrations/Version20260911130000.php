<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `document.full_text`: the text extracted from the file itself, cached for
 * the MCP read tools (`read_document`, `read_request_documents`).
 *
 * It cannot share `document.extracted_text`, which the ingestion pipeline fills
 * with the AI's short summary — DocumentContentReader used that field as its
 * cache, so `mode=text` returned the summary and presented it as the document's
 * complete text (issue #125).
 *
 * Nullable, no backfill: the column fills itself on the next read of each
 * document, and an empty value simply means "extract it now".
 *
 * Fully idempotent: ADD/DROP COLUMN IF [NOT] EXISTS.
 */
final class Version20260911130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document.full_text so MCP full-text extraction stops colliding with the AI summary.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS full_text TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS full_text');
    }
}
