<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426022350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oauth2_dynamic_client_metadata table for RFC 7591 client registrations.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->hasTable('oauth2_dynamic_client_metadata'),
            'oauth2_dynamic_client_metadata already exists; skipping.'
        );

        $this->addSql('CREATE TABLE oauth2_dynamic_client_metadata (client_identifier VARCHAR(32) NOT NULL, client_name VARCHAR(255) NOT NULL, logo_uri VARCHAR(1024) DEFAULT NULL, client_uri VARCHAR(1024) DEFAULT NULL, token_endpoint_auth_method VARCHAR(64) DEFAULT NULL, dynamic BOOLEAN NOT NULL, registration_access_token_hash VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (client_identifier))');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->hasTable('oauth2_dynamic_client_metadata'),
            'oauth2_dynamic_client_metadata already removed; skipping.'
        );

        $this->addSql('DROP TABLE IF EXISTS oauth2_dynamic_client_metadata');
    }
}
