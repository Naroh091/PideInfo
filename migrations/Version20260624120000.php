<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds extinction metadata to `complaint_organism`: an `extinct` flag, the
 * `extinction_date`, and a self-referencing `successor_id` pointing at the
 * active organism that assumed an extinct council's competences (e.g. the
 * dissolved Madrid/Murcia councils → CTBG).
 *
 * Structure only — no data is seeded. The flag/date/successor are set per
 * organism from EasyAdmin.
 *
 * Fully idempotent: column adds use IF NOT EXISTS, the FK and index are
 * guarded against re-creation, so re-running the migration is a no-op.
 */
final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extinct flag, extinction_date and self-referencing successor_id to complaint_organism.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_organism
            ADD COLUMN IF NOT EXISTS extinct BOOLEAN NOT NULL DEFAULT FALSE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_organism
            ADD COLUMN IF NOT EXISTS extinction_date DATE DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_organism
            ADD COLUMN IF NOT EXISTS successor_id UUID DEFAULT NULL
        SQL);

        // Postgres has no ADD CONSTRAINT IF NOT EXISTS — guard via pg_constraint.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_co_successor'
                ) THEN
                    ALTER TABLE complaint_organism
                    ADD CONSTRAINT fk_co_successor
                    FOREIGN KEY (successor_id) REFERENCES complaint_organism (id)
                    ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_co_successor
            ON complaint_organism (successor_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_organism DROP CONSTRAINT IF EXISTS fk_co_successor');
        $this->addSql('DROP INDEX IF EXISTS idx_co_successor');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS successor_id');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS extinction_date');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS extinct');
    }
}
