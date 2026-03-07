<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create access_request_list and access_request_list_item tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE access_request_list (
            id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",
            user_id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",
            organization_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT "private",
            color VARCHAR(7) DEFAULT NULL,
            icon VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            INDEX idx_list_user (user_id),
            INDEX idx_list_org_visibility (organization_id, visibility),
            CONSTRAINT FK_LIST_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_LIST_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE access_request_list_item (
            id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",
            list_id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",
            access_request_id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",
            position INT NOT NULL DEFAULT 0,
            added_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            UNIQUE INDEX uniq_list_request (list_id, access_request_id),
            INDEX idx_list_item_list (list_id),
            INDEX idx_list_item_request (access_request_id),
            CONSTRAINT FK_LIST_ITEM_LIST FOREIGN KEY (list_id) REFERENCES access_request_list (id) ON DELETE CASCADE,
            CONSTRAINT FK_LIST_ITEM_REQUEST FOREIGN KEY (access_request_id) REFERENCES access_request (id) ON DELETE CASCADE,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE access_request_list_item');
        $this->addSql('DROP TABLE access_request_list');
    }
}
