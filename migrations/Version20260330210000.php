<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_notification table for in-app notification system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_notification (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                access_request_id UUID DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                alert_level VARCHAR(20) NOT NULL DEFAULT 'info',
                metadata JSON DEFAULT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_user_notification_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_notification_request FOREIGN KEY (access_request_id) REFERENCES access_request (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_notif_user_unread ON user_notification (user_id, read_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_notif_user_created ON user_notification (user_id, created_at DESC)');
        $this->addSql("COMMENT ON COLUMN user_notification.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN user_notification.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN user_notification.access_request_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN user_notification.read_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN user_notification.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_notification');
    }
}
