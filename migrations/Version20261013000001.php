<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Default privacy (spec §3.5): `access_request.shared_with_organization`
 * as the team visibility gate. Organization membership alone is no longer
 * enough; only explicitly shared rows are visible.
 *
 * Conservative backfill: rows that already had an organization (current teams)
 * start shared so no access is silently revoked. Everything created afterwards
 * requires the owner's explicit act.
 *
 * Fully idempotent: ADD COLUMN IF NOT EXISTS + conditional UPDATE.
 */
final class Version20261013000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add access_request.shared_with_organization (explicit sharing gate) with backfill for existing org rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ADD COLUMN IF NOT EXISTS shared_with_organization BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('UPDATE access_request SET shared_with_organization = TRUE WHERE organization_id IS NOT NULL AND shared_with_organization = FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP COLUMN IF EXISTS shared_with_organization');
    }
}
