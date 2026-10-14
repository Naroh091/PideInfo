<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organization roles (spec §3.4): `user.organization_role` (OWNER/ADMIN/MEMBER).
 *
 * Conservative backfill: current members (pre-billing) start as ADMIN so they
 * keep the management they exercise de facto today. New members join as
 * MEMBER; the team creator stays OWNER.
 *
 * Fully idempotent: ADD COLUMN IF NOT EXISTS + conditional UPDATE.
 */
final class Version20261013000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.organization_role (OWNER/ADMIN/MEMBER) with ADMIN backfill for existing members.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS organization_role VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE \"user\" SET organization_role = 'ADMIN' WHERE organization_id IS NOT NULL AND organization_role IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS organization_role');
    }
}
