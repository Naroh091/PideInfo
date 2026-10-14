<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organization invitations: email + unique token + 7-day expiry.
 *
 * Fully idempotent: CREATE TABLE IF NOT EXISTS + IF NOT EXISTS indexes.
 */
final class Version20261013000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create invitation table (org invites with 7-day expiry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS invitation (
                id UUID NOT NULL PRIMARY KEY,
                email VARCHAR(180) NOT NULL,
                organization_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'MEMBER',
                token VARCHAR(64) NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                accepted_at TIMESTAMPTZ DEFAULT NULL,
                invited_by_id UUID DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_invitation_token ON invitation (token)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invitation_org ON invitation (organization_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invitation_email ON invitation (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invitation');
    }
}
