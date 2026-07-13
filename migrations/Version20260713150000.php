<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Judgment.effective_outcome — one plain sentence saying what the ruling MEANS in practice for
 * whoever asked for the information.
 *
 * The triad (outcome/effect/stance) says what happened procedurally; it does not say what the
 * citizen got. "Anulada por sentencia firme" reads like bad news, and in the BOSCO case it was
 * the opposite: the Supreme Court annulled the CTBG resolution because it had DENIED too much,
 * and ordered the source code handed over. Without this field, neither the agent nor the
 * reader can tell which direction an annulment went.
 */
final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Judgment.effective_outcome: what the ruling means in practice for the requester.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE judgment ADD COLUMN IF NOT EXISTS effective_outcome TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE judgment DROP COLUMN IF EXISTS effective_outcome');
    }
}
