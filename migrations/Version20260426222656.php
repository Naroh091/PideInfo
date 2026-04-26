<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the resolution vector store from `ai_ctbg_resolutions` (CTBG-only, legacy name)
 * to `ai_resolutions` to reflect that it now holds resolutions from every supported
 * transparency council. Wipes the corpus — repopulate with:
 *
 *     bin/console app:resolutions:analyze --vectors-only
 *
 * The new store also stores `resolution_id` in metadata, so re-vectorization is required
 * regardless of whether we tried to preserve the old rows.
 */
final class Version20260426222656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename ai_ctbg_resolutions to ai_resolutions and wipe the corpus so it is re-vectorized with resolution_id metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_ctbg_resolutions') IS NOT NULL THEN DROP TABLE ai_ctbg_resolutions; END IF; END $$");

        $this->addSql("DO $$ BEGIN IF to_regclass('ai_resolutions') IS NULL THEN
            CREATE TABLE ai_resolutions (
                id UUID PRIMARY KEY,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                embedding halfvec(3072)
            );
        END IF; END $$");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_resolutions') IS NOT NULL THEN DROP TABLE ai_resolutions; END IF; END $$");
        // The legacy ai_ctbg_resolutions table is intentionally not recreated — its content
        // is gone, and the bundle would re-create it on first write anyway.
    }
}
