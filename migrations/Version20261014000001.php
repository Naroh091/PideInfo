<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API v1 tokens (resolutions): issuance and revocation mirroring the agent
 * flow. Fully idempotent.
 */
final class Version20261014000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.api_token_issued_at / api_tokens_invalidated_at for API v1 tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS api_token_issued_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS api_tokens_invalidated_at TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS api_token_issued_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS api_tokens_invalidated_at');
    }
}
