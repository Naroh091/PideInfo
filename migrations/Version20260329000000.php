<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260329000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extract complaint fields from access_request into access_request_complaint table';
    }

    public function up(Schema $schema): void
    {
        // Phase 1: Create the new table
        $this->addSql('CREATE TABLE IF NOT EXISTS access_request_complaint (
            id UUID NOT NULL,
            access_request_id UUID NOT NULL,
            external_id VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            deadline_at DATE DEFAULT NULL,
            compliance_deadline_at DATE DEFAULT NULL,
            filed_at DATE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql("COMMENT ON COLUMN access_request_complaint.deadline_at IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request_complaint.compliance_deadline_at IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request_complaint.filed_at IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request_complaint.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request_complaint.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_complaint_access_request ON access_request_complaint (access_request_id)');
        $this->addSql('DO $$ BEGIN ALTER TABLE access_request_complaint ADD CONSTRAINT FK_complaint_access_request FOREIGN KEY (access_request_id) REFERENCES access_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');

        // Phase 2: Migrate existing data (only if the source column still exists)
        $hasColumn = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'access_request' AND column_name = 'complaint_status'"
        );

        if ($hasColumn > 0) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, complaint_status, complaint_deadline_at, compliance_deadline_at FROM access_request WHERE complaint_status != 'none'"
            );

            foreach ($rows as $row) {
                $uuid = Uuid::v7()->toRfc4122();
                $this->addSql(
                    'INSERT INTO access_request_complaint (id, access_request_id, status, deadline_at, compliance_deadline_at, created_at, updated_at) VALUES (:id, :ar_id, :status, :deadline, :compliance, NOW(), NOW()) ON CONFLICT (access_request_id) DO NOTHING',
                    [
                        'id' => $uuid,
                        'ar_id' => $row['id'],
                        'status' => $row['complaint_status'],
                        'deadline' => $row['complaint_deadline_at'],
                        'compliance' => $row['compliance_deadline_at'],
                    ]
                );
            }

            // Phase 3: Drop old columns from access_request
            $this->addSql('ALTER TABLE access_request DROP COLUMN IF EXISTS complaint_status');
            $this->addSql('ALTER TABLE access_request DROP COLUMN IF EXISTS complaint_deadline_at');
            $this->addSql('ALTER TABLE access_request DROP COLUMN IF EXISTS compliance_deadline_at');
        }
    }

    public function down(Schema $schema): void
    {
        // Phase 1: Re-add columns to access_request
        $this->addSql("ALTER TABLE access_request ADD COLUMN IF NOT EXISTS complaint_status VARCHAR(50) NOT NULL DEFAULT 'none'");
        $this->addSql('ALTER TABLE access_request ADD COLUMN IF NOT EXISTS complaint_deadline_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE access_request ADD COLUMN IF NOT EXISTS compliance_deadline_at DATE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN access_request.complaint_deadline_at IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN access_request.compliance_deadline_at IS '(DC2Type:date_immutable)'");

        // Phase 2: Migrate data back
        $this->addSql('UPDATE access_request ar SET
            complaint_status = c.status,
            complaint_deadline_at = c.deadline_at,
            compliance_deadline_at = c.compliance_deadline_at
            FROM access_request_complaint c
            WHERE c.access_request_id = ar.id');

        // Phase 3: Drop the complaint table
        $this->addSql('DROP TABLE IF EXISTS access_request_complaint');
    }
}
