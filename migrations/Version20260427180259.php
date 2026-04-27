<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resizes the pgvector stores from halfvec(3072) to halfvec(4096) so we can keep
 * Qwen3-Embedding-8B at its native dimension (no Matryoshka truncation). Wipes
 * the existing rows because the previously stored vectors are not compatible
 * with the new dimensionality. Repopulate with:
 *   - Resolutions: bin/console app:resolutions:analyze --vectors-only
 *   - Criteria:    bin/console app:ctbg:load-criteria
 *
 * Remember to set CUSTOM_EMBEDDING_DIMENSION=4096 (or leave it at 0 to use the
 * model's native size) and point CUSTOM_EMBEDDING_MODEL at the 8B variant.
 */
final class Version20260427180259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resize ai_resolutions and ai_ctbg_criteria embeddings from halfvec(3072) to halfvec(4096) for Qwen3-Embedding-8B full-size vectors.';
    }

    public function up(Schema $schema): void
    {
        foreach (['ai_resolutions', 'ai_ctbg_criteria'] as $table) {
            $this->addSql(sprintf(
                "DO $$ BEGIN
                    IF to_regclass('%s') IS NOT NULL THEN
                        TRUNCATE TABLE %s;
                        ALTER TABLE %s ALTER COLUMN embedding TYPE halfvec(4096);
                    END IF;
                END $$",
                $table,
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['ai_resolutions', 'ai_ctbg_criteria'] as $table) {
            $this->addSql(sprintf(
                "DO $$ BEGIN
                    IF to_regclass('%s') IS NOT NULL THEN
                        TRUNCATE TABLE %s;
                        ALTER TABLE %s ALTER COLUMN embedding TYPE halfvec(3072);
                    END IF;
                END $$",
                $table,
                $table,
                $table,
            ));
        }
    }
}
