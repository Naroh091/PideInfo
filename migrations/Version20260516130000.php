<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Oficina DIR3 columns to reg_destination so the picker can show the
 * full hierarchy (Unidad → Oficina → Raíz) without losing the office that
 * physically registers each unit. The Oficina is the deepest DIR3 level the
 * SIR/REG export carries (column 1 of the CSV) and was previously read but
 * discarded by ImportRegDestinationsCommand.
 *
 * Idempotent: ADD COLUMN IF NOT EXISTS so re-running is safe. Backfill is NOT
 * done here — the importer fills these columns on its next run; existing
 * rows show "—" until then.
 */
final class Version20260516130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reg_destination.oficina_dir3 / oficina_name (deepest DIR3 level for the picker hierarchy).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reg_destination ADD COLUMN IF NOT EXISTS oficina_dir3 VARCHAR(12) DEFAULT NULL');
        $this->addSql('ALTER TABLE reg_destination ADD COLUMN IF NOT EXISTS oficina_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reg_destination DROP COLUMN IF EXISTS oficina_dir3');
        $this->addSql('ALTER TABLE reg_destination DROP COLUMN IF EXISTS oficina_name');
    }
}
