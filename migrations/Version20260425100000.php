<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wipes the pgvector stores so the corpus can be re-vectorized after switching
 * the embedding backend. The schema (halfvec(3072) + HNSW) is preserved — only
 * the rows are emptied. Re-populate with:
 *   - Resolutions: bin/console app:resolutions:analyze --vectors-only
 *   - Criteria:    bin/console app:ctbg:load-criteria
 */
final class Version20260425100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Empty ai_ctbg_resolutions and ai_ctbg_criteria so the corpus can be re-vectorized with the new embedding model. Schema is preserved.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_ctbg_resolutions') IS NOT NULL THEN TRUNCATE TABLE ai_ctbg_resolutions; END IF; END $$");
        $this->addSql("DO $$ BEGIN IF to_regclass('ai_ctbg_criteria') IS NOT NULL THEN TRUNCATE TABLE ai_ctbg_criteria; END IF; END $$");
    }

    public function down(Schema $schema): void
    {
        // Truncated rows cannot be restored. Re-run the load commands to repopulate.
    }
}
