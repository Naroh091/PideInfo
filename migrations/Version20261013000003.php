<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing subscriptions (spec §3.2): `subscription` table (user XOR
 * organization, Price IDs kept out — they travel via env) so the effective
 * plan, member caps and batch quotas resolve server-side.
 *
 * No rows = Individual plan. Stores no card data.
 *
 * Fully idempotent: CREATE TABLE IF NOT EXISTS + IF NOT EXISTS indexes.
 */
final class Version20261013000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create subscription table (user XOR organization billing, no card data).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS subscription (
                id UUID NOT NULL PRIMARY KEY,
                user_id UUID DEFAULT NULL,
                organization_id UUID DEFAULT NULL,
                plan VARCHAR(32) NOT NULL,
                interval VARCHAR(16) NOT NULL DEFAULT 'month',
                status VARCHAR(16) NOT NULL DEFAULT 'incomplete',
                stripe_customer_id VARCHAR(64) DEFAULT NULL,
                stripe_subscription_id VARCHAR(64) DEFAULT NULL,
                current_period_end TIMESTAMPTZ DEFAULT NULL,
                past_due_since TIMESTAMPTZ DEFAULT NULL,
                cancel_at_period_end BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT chk_subscription_owner CHECK (
                    (user_id IS NOT NULL AND organization_id IS NULL)
                    OR (user_id IS NULL AND organization_id IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_subscription_stripe_id ON subscription (stripe_subscription_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_subscription_user ON subscription (user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_subscription_org ON subscription (organization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS subscription');
    }
}
