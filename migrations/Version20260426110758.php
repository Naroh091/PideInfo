<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426110758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent_token_issued_at and agent_tokens_invalidated_at columns to user table for agent JWT lifecycle.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user');

        if (!$table->hasColumn('agent_token_issued_at')) {
            $this->addSql('ALTER TABLE "user" ADD COLUMN agent_token_issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        }

        if (!$table->hasColumn('agent_tokens_invalidated_at')) {
            $this->addSql('ALTER TABLE "user" ADD COLUMN agent_tokens_invalidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('user');

        if ($table->hasColumn('agent_tokens_invalidated_at')) {
            $this->addSql('ALTER TABLE "user" DROP COLUMN agent_tokens_invalidated_at');
        }

        if ($table->hasColumn('agent_token_issued_at')) {
            $this->addSql('ALTER TABLE "user" DROP COLUMN agent_token_issued_at');
        }
    }
}
