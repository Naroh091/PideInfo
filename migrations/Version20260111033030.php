<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111033030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE access_request (id BINARY(16) NOT NULL, external_id VARCHAR(100) DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL, complaint_status VARCHAR(50) NOT NULL, court_status VARCHAR(50) NOT NULL, sent_at DATE NOT NULL, acknowledged_at DATE DEFAULT NULL, resolved_at DATE DEFAULT NULL, deadline_at DATE NOT NULL, original_deadline_at DATE DEFAULT NULL, complaint_deadline_at DATE DEFAULT NULL, compliance_deadline_at DATE DEFAULT NULL, extension_count INT NOT NULL, extension_reason LONGTEXT DEFAULT NULL, resolution_notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, organization_id BINARY(16) DEFAULT NULL, public_body_id BINARY(16) NOT NULL, applicable_law_id BINARY(16) NOT NULL, INDEX IDX_F3B2558A32C8A3DE (organization_id), INDEX IDX_F3B2558AC0BC4236 (public_body_id), INDEX IDX_F3B2558A9311B950 (applicable_law_id), INDEX idx_status (status), INDEX idx_deadline (deadline_at), INDEX idx_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE applicable_law (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, short_code VARCHAR(100) NOT NULL, response_deadline_days INT NOT NULL, extension_days INT NOT NULL, max_extensions INT NOT NULL, complaint_deadline_days INT NOT NULL, compliance_after_complaint_days INT NOT NULL, silence_is_positive TINYINT NOT NULL, notes LONGTEXT DEFAULT NULL, official_url VARCHAR(255) DEFAULT NULL, autonomous_community_id BINARY(16) DEFAULT NULL, INDEX IDX_2AD25F01414B1E1 (autonomous_community_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE autonomous_community (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(10) NOT NULL, UNIQUE INDEX UNIQ_7DB1DCA77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE deadline_history (id BINARY(16) NOT NULL, deadline_type VARCHAR(50) NOT NULL, previous_deadline DATE DEFAULT NULL, new_deadline DATE NOT NULL, reason VARCHAR(50) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, access_request_id BINARY(16) NOT NULL, trigger_document_id BINARY(16) DEFAULT NULL, INDEX IDX_462198E968402024 (access_request_id), INDEX IDX_462198E9BC475285 (trigger_document_id), INDEX idx_deadline_request_created (access_request_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE document (id BINARY(16) NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_size INT NOT NULL, type VARCHAR(50) NOT NULL, extracted_text LONGTEXT DEFAULT NULL, ai_metadata JSON DEFAULT NULL, processed TINYINT NOT NULL, processing_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, access_request_id BINARY(16) DEFAULT NULL, uploaded_by_id BINARY(16) NOT NULL, INDEX IDX_D8698A7668402024 (access_request_id), INDEX IDX_D8698A76A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE organization (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, cif VARCHAR(100) DEFAULT NULL, address LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE public_body (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, level VARCHAR(50) NOT NULL, address LONGTEXT DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, transparency_portal_url VARCHAR(255) DEFAULT NULL, registry_code VARCHAR(100) DEFAULT NULL, autonomous_community_id BINARY(16) DEFAULT NULL, INDEX IDX_A0F549631414B1E1 (autonomous_community_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE resolution (id BINARY(16) NOT NULL, reference_number VARCHAR(100) NOT NULL, resolution_date DATE NOT NULL, outcome VARCHAR(50) NOT NULL, summary LONGTEXT NOT NULL, full_text LONGTEXT NOT NULL, embedding LONGBLOB DEFAULT NULL, source VARCHAR(255) NOT NULL, source_url VARCHAR(255) DEFAULT NULL, topics JSON DEFAULT NULL, public_body_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, embedded_at DATETIME DEFAULT NULL, INDEX idx_outcome (outcome), INDEX idx_resolution_date (resolution_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE status_history (id BINARY(16) NOT NULL, status_type VARCHAR(50) NOT NULL, from_status VARCHAR(50) NOT NULL, to_status VARCHAR(50) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, access_request_id BINARY(16) NOT NULL, trigger_document_id BINARY(16) DEFAULT NULL, INDEX IDX_2F6A07CE68402024 (access_request_id), INDEX IDX_2F6A07CEBC475285 (trigger_document_id), INDEX idx_request_created (access_request_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE `user` (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, is_verified TINYINT NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, organization_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), INDEX IDX_8D93D64932C8A3DE (organization_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558AC0BC4236 FOREIGN KEY (public_body_id) REFERENCES public_body (id)');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A9311B950 FOREIGN KEY (applicable_law_id) REFERENCES applicable_law (id)');
        $this->addSql('ALTER TABLE applicable_law ADD CONSTRAINT FK_2AD25F01414B1E1 FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community (id)');
        $this->addSql('ALTER TABLE deadline_history ADD CONSTRAINT FK_462198E968402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id)');
        $this->addSql('ALTER TABLE deadline_history ADD CONSTRAINT FK_462198E9BC475285 FOREIGN KEY (trigger_document_id) REFERENCES document (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7668402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE public_body ADD CONSTRAINT FK_A0F549631414B1E1 FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community (id)');
        $this->addSql('ALTER TABLE status_history ADD CONSTRAINT FK_2F6A07CE68402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id)');
        $this->addSql('ALTER TABLE status_history ADD CONSTRAINT FK_2F6A07CEBC475285 FOREIGN KEY (trigger_document_id) REFERENCES document (id)');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D64932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request DROP FOREIGN KEY FK_F3B2558AA76ED395');
        $this->addSql('ALTER TABLE access_request DROP FOREIGN KEY FK_F3B2558A32C8A3DE');
        $this->addSql('ALTER TABLE access_request DROP FOREIGN KEY FK_F3B2558AC0BC4236');
        $this->addSql('ALTER TABLE access_request DROP FOREIGN KEY FK_F3B2558A9311B950');
        $this->addSql('ALTER TABLE applicable_law DROP FOREIGN KEY FK_2AD25F01414B1E1');
        $this->addSql('ALTER TABLE deadline_history DROP FOREIGN KEY FK_462198E968402024');
        $this->addSql('ALTER TABLE deadline_history DROP FOREIGN KEY FK_462198E9BC475285');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7668402024');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE public_body DROP FOREIGN KEY FK_A0F549631414B1E1');
        $this->addSql('ALTER TABLE status_history DROP FOREIGN KEY FK_2F6A07CE68402024');
        $this->addSql('ALTER TABLE status_history DROP FOREIGN KEY FK_2F6A07CEBC475285');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D64932C8A3DE');
        $this->addSql('DROP TABLE access_request');
        $this->addSql('DROP TABLE applicable_law');
        $this->addSql('DROP TABLE autonomous_community');
        $this->addSql('DROP TABLE deadline_history');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE public_body');
        $this->addSql('DROP TABLE resolution');
        $this->addSql('DROP TABLE status_history');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
