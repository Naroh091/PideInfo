<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds reg_destination.submission_target_id — the PublicBody that the picker
 * should surface for this unit. Until now reg_destination.public_body_id was
 * always the DIR3 Raíz, which meant intermediate organisms (Puertos del Estado,
 * Confederaciones Hidrográficas, …) and unidades only reachable as text inside
 * the unit picker, never as their own searchable entry.
 *
 * The new column points to the deepest PublicBody we have for the row
 * (Unidad → Organismo → Raíz fallback). Existing rows are backfilled to
 * public_body_id so behaviour stays identical until the importer re-runs and
 * refines the pointer.
 *
 * Idempotent: every statement guarded with IF [NOT] EXISTS or via pg_catalog.
 */
final class Version20260518120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reg_destination.submission_target_id pointing to the picker-visible PublicBody (Unidad/Organismo/Raíz).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reg_destination ADD COLUMN IF NOT EXISTS submission_target_id UUID DEFAULT NULL');
        // Backfill so existing rows continue to be reachable through their Raíz.
        $this->addSql('UPDATE reg_destination SET submission_target_id = public_body_id WHERE submission_target_id IS NULL');
        $this->addSql('ALTER TABLE reg_destination ALTER COLUMN submission_target_id SET NOT NULL');
        // FK guarded so the migration stays idempotent.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_reg_destination_submission_target'
                ) THEN
                    ALTER TABLE reg_destination
                        ADD CONSTRAINT fk_reg_destination_submission_target
                        FOREIGN KEY (submission_target_id) REFERENCES public_body (id)
                        ON DELETE RESTRICT;
                END IF;
            END
            $$;
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reg_destination_submission_target ON reg_destination (submission_target_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_reg_destination_submission_target');
        $this->addSql('ALTER TABLE reg_destination DROP CONSTRAINT IF EXISTS fk_reg_destination_submission_target');
        $this->addSql('ALTER TABLE reg_destination DROP COLUMN IF EXISTS submission_target_id');
    }
}
