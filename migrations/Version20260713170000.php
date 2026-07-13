<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Denormalizes the judicial status onto the resolution row.
 *
 * Without it, neither the public filter ("muéstrame las anuladas") nor the listing cards are
 * possible: the listing goes through Elasticsearch, which can only filter what is in the index,
 * and reaching for the judgments per card would be one query per row.
 *
 * The value is derived — App\Service\Judgment\JudicialStatus::of() is its only author — so the
 * backfill lives in `app:judgments:refresh-status`, not here: it needs the entity logic (the
 * direction of an annulment depends on the judgment's transparency stance).
 */
final class Version20260713170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add resolution.judicial_status (denormalized from the judgment chain)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE resolution
            ADD COLUMN IF NOT EXISTS judicial_status VARCHAR(40) NOT NULL DEFAULT 'no_recurrida'
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_judicial_status ON resolution (judicial_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_resolution_judicial_status');
        $this->addSql('ALTER TABLE resolution DROP COLUMN IF EXISTS judicial_status');
    }
}
