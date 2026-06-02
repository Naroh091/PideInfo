<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla hearing_process: trámites de audiencia de una reclamación, con su
 * ventana de alegaciones (start_date/end_date) calculada a partir de
 * hearing_days + hearing_days_type extraídos del documento que lo abre.
 *
 * Idempotente: CREATE TABLE/INDEX IF NOT EXISTS, constraints envueltas en
 * bloques DO $$ ... EXCEPTION WHEN duplicate_object.
 */
final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hearing_process table (trámites de audiencia of a complaint)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS hearing_process (
                id UUID NOT NULL,
                complaint_id UUID NOT NULL,
                trigger_document_id UUID DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                hearing_days SMALLINT NOT NULL,
                hearing_days_type VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql("COMMENT ON COLUMN hearing_process.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.complaint_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.trigger_document_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.start_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.end_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hearing_complaint_end ON hearing_process (complaint_id, end_date)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hearing_trigger_document ON hearing_process (trigger_document_id)');

        $this->addSql('DO $$ BEGIN ALTER TABLE hearing_process ADD CONSTRAINT FK_hearing_complaint FOREIGN KEY (complaint_id) REFERENCES access_request_complaint (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE hearing_process ADD CONSTRAINT FK_hearing_trigger_document FOREIGN KEY (trigger_document_id) REFERENCES document (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hearing_process');
    }
}
