<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the User postal-address province/municipality columns from the
 * INE-coded variants (`address_province_code` VARCHAR(2),
 * `address_municipality_code` VARCHAR(5)) to plain-name columns. REG / RED
 * SARA expects the textual labels from its own dropdowns ("Madrid", "Abia
 * de la Obispalía"…) — passing INE codes through never matched, so we
 * store what REG accepts directly.
 *
 * Idempotent: works whether the columns are already renamed (re-run safe)
 * or still on the old name. Also widens to fit the longest Spanish
 * municipality names.
 */
final class Version20260513120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename user.address_province_code/municipality_code → address_province/municipality (varchar names instead of INE codes).';
    }

    public function up(Schema $schema): void
    {
        // Province
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_province_code'
                ) THEN
                    ALTER TABLE "user" RENAME COLUMN address_province_code TO address_province;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_province'
                ) THEN
                    ALTER TABLE "user" ADD COLUMN address_province VARCHAR(100);
                END IF;
                ALTER TABLE "user" ALTER COLUMN address_province TYPE VARCHAR(100);
            END $$
        SQL);

        // Municipality
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_municipality_code'
                ) THEN
                    ALTER TABLE "user" RENAME COLUMN address_municipality_code TO address_municipality;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_municipality'
                ) THEN
                    ALTER TABLE "user" ADD COLUMN address_municipality VARCHAR(120);
                END IF;
                ALTER TABLE "user" ALTER COLUMN address_municipality TYPE VARCHAR(120);
            END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_province'
                ) THEN
                    ALTER TABLE "user" ALTER COLUMN address_province TYPE VARCHAR(2);
                    ALTER TABLE "user" RENAME COLUMN address_province TO address_province_code;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'user' AND column_name = 'address_municipality'
                ) THEN
                    ALTER TABLE "user" ALTER COLUMN address_municipality TYPE VARCHAR(5);
                    ALTER TABLE "user" RENAME COLUMN address_municipality TO address_municipality_code;
                END IF;
            END $$
        SQL);
    }
}
