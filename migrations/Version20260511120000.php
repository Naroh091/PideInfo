<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resolution / complaint result columns — orthogonal to the workflow status.
 *
 * Adds:
 *   - access_request.resolution_result   (granted | partially_granted | denied | inadmitted | silence | NULL)
 *   - access_request_complaint.complaint_result (upheld | partially_upheld | dismissed | inadmitted | archived | NULL)
 *
 * The existing `status` column keeps tracking workflow position. The new fields
 * carry the actual decision so it survives later transitions like
 * granted_completed pisando un partially_granted previo.
 *
 * Idempotent: every DDL is guarded with IF [NOT] EXISTS; backfills are guarded
 * with `WHERE … IS NULL`.
 */
final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add resolution_result and complaint_result columns; backfill from current status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request
                ADD COLUMN IF NOT EXISTS resolution_result VARCHAR(30) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_request_complaint
                ADD COLUMN IF NOT EXISTS complaint_result VARCHAR(30) DEFAULT NULL
        SQL);

        // Backfill access_request.resolution_result from the current status.
        $this->addSql(<<<'SQL'
            UPDATE access_request
            SET resolution_result = 'granted'
            WHERE resolution_result IS NULL AND status IN ('granted', 'granted_completed')
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request
            SET resolution_result = 'partially_granted'
            WHERE resolution_result IS NULL AND status = 'partially_granted'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request
            SET resolution_result = 'denied'
            WHERE resolution_result IS NULL AND status = 'denied'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request
            SET resolution_result = 'inadmitted'
            WHERE resolution_result IS NULL AND status = 'inadmitted'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request
            SET resolution_result = 'silence'
            WHERE resolution_result IS NULL AND status = 'delayed'
        SQL);

        // Backfill access_request_complaint.complaint_result from the current status.
        $this->addSql(<<<'SQL'
            UPDATE access_request_complaint
            SET complaint_result = 'upheld'
            WHERE complaint_result IS NULL AND status = 'complaint_granted'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request_complaint
            SET complaint_result = 'dismissed'
            WHERE complaint_result IS NULL AND status = 'complaint_denied'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_request_complaint
            SET complaint_result = 'archived'
            WHERE complaint_result IS NULL AND status = 'complaint_archived'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE access_request_complaint
                DROP COLUMN IF EXISTS complaint_result
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_request
                DROP COLUMN IF EXISTS resolution_result
        SQL);
    }
}
