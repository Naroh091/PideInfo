<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Read-only notice (dunning decision): `subscription.readonly_notified_at`
 * records that the owner was already notified on entering overdue/unpaid
 * status, so `app:billing:notify-readonly` notifies only once.
 *
 * Fully idempotent: ADD COLUMN IF NOT EXISTS.
 */
final class Version20261013000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription.readonly_notified_at for one-shot read-only notices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD COLUMN IF NOT EXISTS readonly_notified_at TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP COLUMN IF EXISTS readonly_notified_at');
    }
}
