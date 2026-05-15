<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REG / RED SARA submission channel.
 *
 * 1. User postal address + contact phone — required by REG step 1 (Datos del solicitante).
 *    All nullable so existing users can complete the profile lazily when they first send
 *    to a REG-bound body.
 *
 * 2. PublicBody dir3_code (Raíz/Organismo principal) + imported_from_reg flag to mark
 *    bodies auto-created by the import command for later curation.
 *
 * 3. reg_destination table (Unidad de destino in DIR3 terms). Each row is a leaf that
 *    REG step 2 accepts. The intermediate organism (between Raíz and Unidad) is
 *    denormalised because we only use it for the picker label.
 *
 * 4. AccessRequest gets reg_destination_id (target unit) + expone / solicita
 *    (REG step 2 requires two textareas of max 4000 chars; description stays as
 *    the source of truth for AGE / email).
 *
 * Idempotent: every DDL is guarded with IF [NOT] EXISTS.
 */
final class Version20260512120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'REG/RED SARA channel: user postal data, PublicBody dir3, reg_destination table, AccessRequest expone/solicita.';
    }

    public function up(Schema $schema): void
    {
        // --- User: postal address + contact phone ---
        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                ADD COLUMN IF NOT EXISTS address_street_type VARCHAR(30),
                ADD COLUMN IF NOT EXISTS address_line VARCHAR(160),
                ADD COLUMN IF NOT EXISTS address_country VARCHAR(2),
                ADD COLUMN IF NOT EXISTS address_province_code VARCHAR(2),
                ADD COLUMN IF NOT EXISTS address_municipality_code VARCHAR(5),
                ADD COLUMN IF NOT EXISTS address_postal_code VARCHAR(10),
                ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20)
        SQL);

        // --- PublicBody: DIR3 + import provenance flag ---
        $this->addSql(<<<'SQL'
            ALTER TABLE public_body
                ADD COLUMN IF NOT EXISTS dir3_code VARCHAR(12),
                ADD COLUMN IF NOT EXISTS imported_from_reg BOOLEAN NOT NULL DEFAULT FALSE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_public_body_dir3 ON public_body (dir3_code)
        SQL);

        // --- reg_destination: DIR3 leaf units ---
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reg_destination (
                id UUID NOT NULL,
                public_body_id UUID NOT NULL,
                dir3_code VARCHAR(12) NOT NULL,
                name VARCHAR(255) NOT NULL,
                intermediate_organism_dir3 VARCHAR(12),
                intermediate_organism_name VARCHAR(255),
                comunidad VARCHAR(100),
                provincia VARCHAR(100),
                nivel_administracion VARCHAR(50),
                activated_at DATE,
                disabled_at DATE,
                CONSTRAINT pk_reg_destination PRIMARY KEY (id),
                CONSTRAINT uniq_reg_destination_dir3 UNIQUE (dir3_code),
                CONSTRAINT fk_reg_destination_public_body
                    FOREIGN KEY (public_body_id) REFERENCES public_body (id)
                    ON DELETE RESTRICT
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_reg_destination_public_body
                ON reg_destination (public_body_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_reg_destination_provincia
                ON reg_destination (provincia)
                WHERE disabled_at IS NULL
        SQL);

        // --- AccessRequest: reg destination + structured body (expone/solicita) ---
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request
                ADD COLUMN IF NOT EXISTS reg_destination_id UUID,
                ADD COLUMN IF NOT EXISTS expone TEXT,
                ADD COLUMN IF NOT EXISTS solicita TEXT
        SQL);

        // FK as a separate ALTER so re-runs don't fail if the column already exists
        // but the constraint doesn't (older partial states).
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_access_request_reg_destination'
                ) THEN
                    ALTER TABLE access_request
                        ADD CONSTRAINT fk_access_request_reg_destination
                        FOREIGN KEY (reg_destination_id) REFERENCES reg_destination (id)
                        ON DELETE SET NULL;
                END IF;
            END $$
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_access_request_reg_destination
                ON access_request (reg_destination_id)
                WHERE reg_destination_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_access_request_reg_destination');
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request
                DROP CONSTRAINT IF EXISTS fk_access_request_reg_destination,
                DROP COLUMN IF EXISTS solicita,
                DROP COLUMN IF EXISTS expone,
                DROP COLUMN IF EXISTS reg_destination_id
        SQL);

        $this->addSql('DROP INDEX IF EXISTS idx_reg_destination_provincia');
        $this->addSql('DROP INDEX IF EXISTS idx_reg_destination_public_body');
        $this->addSql('DROP TABLE IF EXISTS reg_destination');

        $this->addSql('DROP INDEX IF EXISTS idx_public_body_dir3');
        $this->addSql(<<<'SQL'
            ALTER TABLE public_body
                DROP COLUMN IF EXISTS imported_from_reg,
                DROP COLUMN IF EXISTS dir3_code
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE "user"
                DROP COLUMN IF EXISTS contact_phone,
                DROP COLUMN IF EXISTS address_postal_code,
                DROP COLUMN IF EXISTS address_municipality_code,
                DROP COLUMN IF EXISTS address_province_code,
                DROP COLUMN IF EXISTS address_country,
                DROP COLUMN IF EXISTS address_line,
                DROP COLUMN IF EXISTS address_street_type
        SQL);
    }
}
