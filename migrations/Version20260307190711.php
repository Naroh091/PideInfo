<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260307190711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE IF NOT EXISTS access_request (id UUID NOT NULL, external_id VARCHAR(100) DEFAULT NULL, alternative_references JSON DEFAULT NULL, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, status VARCHAR(50) NOT NULL, complaint_status VARCHAR(50) NOT NULL, court_status VARCHAR(50) NOT NULL, third_party_status VARCHAR(50) NOT NULL, third_party_allegations_started_at DATE DEFAULT NULL, third_party_allegations_deadline_at DATE DEFAULT NULL, deadline_suspended_at DATE DEFAULT NULL, suspended_days_remaining INT DEFAULT NULL, redirected_at DATE DEFAULT NULL, sent_at DATE NOT NULL, acknowledged_at DATE DEFAULT NULL, processing_started_at DATE DEFAULT NULL, resolved_at DATE DEFAULT NULL, deadline_at DATE NOT NULL, original_deadline_at DATE DEFAULT NULL, complaint_deadline_at DATE DEFAULT NULL, compliance_deadline_at DATE DEFAULT NULL, extension_count INT NOT NULL, extension_reason TEXT DEFAULT NULL, resolution_notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, organization_id UUID DEFAULT NULL, public_body_id UUID NOT NULL, original_public_body_id UUID DEFAULT NULL, applicable_law_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_F3B2558A32C8A3DE ON access_request (organization_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_F3B2558AC0BC4236 ON access_request (public_body_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_F3B2558A3BA76F8A ON access_request (original_public_body_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_F3B2558A9311B950 ON access_request (applicable_law_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_status ON access_request (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_deadline ON access_request (deadline_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user ON access_request (user_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS access_request_list(id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, visibility VARCHAR(20) NOT NULL, color VARCHAR(7) DEFAULT NULL, icon VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_E72347A032C8A3DE ON access_request_list (organization_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_list_user ON access_request_list (user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_list_org_visibility ON access_request_list (organization_id, visibility)');
        $this->addSql('CREATE TABLE IF NOT EXISTS access_request_list_item (id UUID NOT NULL, position INT NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, list_id UUID NOT NULL, access_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_1FEE37853DAE168B ON access_request_list_item (list_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_1FEE378568402024 ON access_request_list_item (access_request_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_list_request ON access_request_list_item (list_id, access_request_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS applicable_law (id UUID NOT NULL, name VARCHAR(255) NOT NULL, short_code VARCHAR(100) NOT NULL, response_deadline_value INT NOT NULL, response_deadline_unit VARCHAR(20) NOT NULL, extension_value INT NOT NULL, extension_unit VARCHAR(20) NOT NULL, max_extensions INT NOT NULL, complaint_deadline_days INT NOT NULL, compliance_after_complaint_days INT NOT NULL, silence_is_positive BOOLEAN NOT NULL, notes TEXT DEFAULT NULL, official_url VARCHAR(255) DEFAULT NULL, autonomous_community_id UUID DEFAULT NULL, complaint_organism_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2AD25F01414B1E1 ON applicable_law (autonomous_community_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2AD25F0A684F2EF ON applicable_law (complaint_organism_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS autonomous_community (id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(10) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_7DB1DCA77153098 ON autonomous_community (code)');
        $this->addSql('CREATE TABLE IF NOT EXISTS complaint_organism (id UUID NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(100) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, complaint_form_url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, address TEXT DEFAULT NULL, notes TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE IF NOT EXISTS custom_deadline (id UUID NOT NULL, deadline_at DATE NOT NULL, description TEXT NOT NULL, notify_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, notified_day_before_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, access_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_5295939668402024 ON custom_deadline (access_request_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_custom_deadline_request_date ON custom_deadline (access_request_id, deadline_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_custom_deadline_notified ON custom_deadline (notified_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_custom_deadline_notified_day_before ON custom_deadline (notified_day_before_at)');
        $this->addSql('CREATE TABLE IF NOT EXISTS deadline_history (id UUID NOT NULL, deadline_type VARCHAR(50) NOT NULL, previous_deadline DATE DEFAULT NULL, new_deadline DATE NOT NULL, reason VARCHAR(50) NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, access_request_id UUID NOT NULL, trigger_document_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_462198E968402024 ON deadline_history (access_request_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_462198E9BC475285 ON deadline_history (trigger_document_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_deadline_request_created ON deadline_history (access_request_id, created_at)');
        $this->addSql('CREATE TABLE IF NOT EXISTS document (id UUID NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_size INT NOT NULL, type VARCHAR(50) NOT NULL, extracted_text TEXT DEFAULT NULL, ai_metadata JSON DEFAULT NULL, processed BOOLEAN NOT NULL, processing_error TEXT DEFAULT NULL, match_method VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, access_request_id UUID DEFAULT NULL, uploaded_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_D8698A7668402024 ON document (access_request_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_D8698A76A2B28FE8 ON document (uploaded_by_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS organization (id UUID NOT NULL, name VARCHAR(255) NOT NULL, cif VARCHAR(100) DEFAULT NULL, address TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE IF NOT EXISTS public_body (id UUID NOT NULL, name VARCHAR(255) NOT NULL, level VARCHAR(50) NOT NULL, address TEXT DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, transparency_portal_url VARCHAR(255) DEFAULT NULL, registry_code VARCHAR(100) DEFAULT NULL, autonomous_community_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_A0F549631414B1E1 ON public_body (autonomous_community_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS resolution (id UUID NOT NULL, reference_number VARCHAR(100) NOT NULL, resolution_date DATE NOT NULL, outcome VARCHAR(50) NOT NULL, summary TEXT NOT NULL, full_text TEXT NOT NULL, source VARCHAR(255) NOT NULL, source_url VARCHAR(255) DEFAULT NULL, topics JSON DEFAULT NULL, public_body_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_outcome ON resolution (outcome)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_resolution_date ON resolution (resolution_date)');
        $this->addSql('CREATE TABLE IF NOT EXISTS status_history (id UUID NOT NULL, status_type VARCHAR(50) NOT NULL, from_status VARCHAR(50) NOT NULL, to_status VARCHAR(50) NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, access_request_id UUID NOT NULL, trigger_document_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2F6A07CE68402024 ON status_history (access_request_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_2F6A07CEBC475285 ON status_history (trigger_document_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_request_created ON status_history (access_request_id, created_at)');
        $this->addSql('CREATE TABLE IF NOT EXISTS "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_8D93D64932C8A3DE ON "user" (organization_id)');
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558AC0BC4236 FOREIGN KEY (public_body_id) REFERENCES public_body (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A3BA76F8A FOREIGN KEY (original_public_body_id) REFERENCES public_body (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A9311B950 FOREIGN KEY (applicable_law_id) REFERENCES applicable_law (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request_list ADD CONSTRAINT FK_E72347A0A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request_list ADD CONSTRAINT FK_E72347A032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request_list_item ADD CONSTRAINT FK_1FEE37853DAE168B FOREIGN KEY (list_id) REFERENCES access_request_list (id) ON DELETE CASCADE NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request_list_item ADD CONSTRAINT FK_1FEE378568402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id) ON DELETE CASCADE NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE applicable_law ADD CONSTRAINT FK_2AD25F01414B1E1 FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE applicable_law ADD CONSTRAINT FK_2AD25F0A684F2EF FOREIGN KEY (complaint_organism_id) REFERENCES complaint_organism (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE custom_deadline ADD CONSTRAINT FK_5295939668402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE deadline_history ADD CONSTRAINT FK_462198E968402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE deadline_history ADD CONSTRAINT FK_462198E9BC475285 FOREIGN KEY (trigger_document_id) REFERENCES document (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE document ADD CONSTRAINT FK_D8698A7668402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE document ADD CONSTRAINT FK_D8698A76A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES "user" (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE public_body ADD CONSTRAINT FK_A0F549631414B1E1 FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE status_history ADD CONSTRAINT FK_2F6A07CE68402024 FOREIGN KEY (access_request_id) REFERENCES access_request (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE status_history ADD CONSTRAINT FK_2F6A07CEBC475285 FOREIGN KEY (trigger_document_id) REFERENCES document (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE "user" ADD CONSTRAINT FK_8D93D64932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) NOT DEFERRABLE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS FK_F3B2558AA76ED395');
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS FK_F3B2558A32C8A3DE');
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS FK_F3B2558AC0BC4236');
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS FK_F3B2558A3BA76F8A');
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS FK_F3B2558A9311B950');
        $this->addSql('ALTER TABLE access_request_list DROP CONSTRAINT IF EXISTS FK_E72347A0A76ED395');
        $this->addSql('ALTER TABLE access_request_list DROP CONSTRAINT IF EXISTS FK_E72347A032C8A3DE');
        $this->addSql('ALTER TABLE access_request_list_item DROP CONSTRAINT IF EXISTS FK_1FEE37853DAE168B');
        $this->addSql('ALTER TABLE access_request_list_item DROP CONSTRAINT IF EXISTS FK_1FEE378568402024');
        $this->addSql('ALTER TABLE applicable_law DROP CONSTRAINT IF EXISTS FK_2AD25F01414B1E1');
        $this->addSql('ALTER TABLE applicable_law DROP CONSTRAINT IF EXISTS FK_2AD25F0A684F2EF');
        $this->addSql('ALTER TABLE custom_deadline DROP CONSTRAINT IF EXISTS FK_5295939668402024');
        $this->addSql('ALTER TABLE deadline_history DROP CONSTRAINT IF EXISTS FK_462198E968402024');
        $this->addSql('ALTER TABLE deadline_history DROP CONSTRAINT IF EXISTS FK_462198E9BC475285');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_D8698A7668402024');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_D8698A76A2B28FE8');
        $this->addSql('ALTER TABLE public_body DROP CONSTRAINT IF EXISTS FK_A0F549631414B1E1');
        $this->addSql('ALTER TABLE status_history DROP CONSTRAINT IF EXISTS FK_2F6A07CE68402024');
        $this->addSql('ALTER TABLE status_history DROP CONSTRAINT IF EXISTS FK_2F6A07CEBC475285');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT IF EXISTS FK_8D93D64932C8A3DE');
        $this->addSql('DROP TABLE IF EXISTS access_request');
        $this->addSql('DROP TABLE IF EXISTS access_request_list');
        $this->addSql('DROP TABLE IF EXISTS access_request_list_item');
        $this->addSql('DROP TABLE IF EXISTS applicable_law');
        $this->addSql('DROP TABLE IF EXISTS autonomous_community');
        $this->addSql('DROP TABLE IF EXISTS complaint_organism');
        $this->addSql('DROP TABLE IF EXISTS custom_deadline');
        $this->addSql('DROP TABLE IF EXISTS deadline_history');
        $this->addSql('DROP TABLE IF EXISTS document');
        $this->addSql('DROP TABLE IF EXISTS organization');
        $this->addSql('DROP TABLE IF EXISTS public_body');
        $this->addSql('DROP TABLE IF EXISTS resolution');
        $this->addSql('DROP TABLE IF EXISTS status_history');
        $this->addSql('DROP TABLE IF EXISTS "user"');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
