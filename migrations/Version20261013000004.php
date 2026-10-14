<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stripe webhook idempotency (spec §6): `processed_stripe_event`
 * with unique `event_id` — duplicate deliveries are detected by constraint.
 *
 * Fully idempotent: CREATE TABLE IF NOT EXISTS + IF NOT EXISTS indexes.
 */
final class Version20261013000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create processed_stripe_event table (webhook idempotency).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS processed_stripe_event (
                id UUID NOT NULL PRIMARY KEY,
                event_id VARCHAR(64) NOT NULL,
                type VARCHAR(64) NOT NULL,
                received_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_processed_stripe_event ON processed_stripe_event (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS processed_stripe_event');
    }
}
