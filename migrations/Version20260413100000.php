<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reminder table for user-scheduled reminders on access requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS reminder (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                access_request_id UUID DEFAULT NULL,
                remind_at DATE NOT NULL,
                note TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reminder_user_date ON reminder (user_id, remind_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reminder_request_date ON reminder (access_request_id, remind_at)');

        $this->addSql(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_reminder_user'
                ) THEN
                    ALTER TABLE reminder
                        ADD CONSTRAINT fk_reminder_user
                        FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_reminder_access_request'
                ) THEN
                    ALTER TABLE reminder
                        ADD CONSTRAINT fk_reminder_access_request
                        FOREIGN KEY (access_request_id) REFERENCES access_request (id) ON DELETE CASCADE;
                END IF;
            END$$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS reminder');
    }
}
