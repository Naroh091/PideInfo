<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds three nullable columns to `"user"` to cache the AI-generated activity
 * summary shown at the top of the dashboard. Pre-generated asynchronously by
 * `App\MessageHandler\WarmActivitySummaryHandler`; rendered instantly from cache.
 */
final class Version20260430192029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add activity_summary_html / activity_summary_fingerprint / activity_summary_updated_at to "user".';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS activity_summary_html TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS activity_summary_fingerprint VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS activity_summary_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "user".activity_summary_updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS activity_summary_html');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS activity_summary_fingerprint');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS activity_summary_updated_at');
    }
}
