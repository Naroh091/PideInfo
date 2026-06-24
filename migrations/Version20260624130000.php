<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a nullable `hide_at` to `usage_hint`. When that date is reached, the
 * daily `app:usage-hints:hide-expired` command flips `is_active` to false so the
 * hint stops being shown. Null = never expires.
 *
 * Fully idempotent: the column add uses IF NOT EXISTS, so re-running is a no-op.
 */
final class Version20260624130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable hide_at to usage_hint (auto-deactivation date).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE usage_hint
            ADD COLUMN IF NOT EXISTS hide_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        SQL);

        $this->addSql("COMMENT ON COLUMN usage_hint.hide_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usage_hint DROP COLUMN IF EXISTS hide_at');
    }
}
