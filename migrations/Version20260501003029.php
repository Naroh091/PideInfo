<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501003029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent_task table for web→agent task queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS agent_task (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                access_request_id UUID DEFAULT NULL,
                type VARCHAR(64) NOT NULL,
                mode VARCHAR(32) DEFAULT NULL,
                payload JSON NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                result JSON DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_agent_task_user_status ON agent_task (user_id, status)');
        $this->addSql('COMMENT ON COLUMN agent_task.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN agent_task.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN agent_task.access_request_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN agent_task.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN agent_task.claimed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN agent_task.completed_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE agent_task ADD CONSTRAINT fk_agent_task_user FOREIGN KEY (user_id)
                    REFERENCES "user"(id) ON DELETE CASCADE;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE agent_task ADD CONSTRAINT fk_agent_task_access_request FOREIGN KEY (access_request_id)
                    REFERENCES access_request(id) ON DELETE SET NULL;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS agent_task');
    }
}
