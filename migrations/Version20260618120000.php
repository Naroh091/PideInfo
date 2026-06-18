<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the ComplaintOrganism for the CTRM (Comisionado de Transparencia de la
 * Región de Murcia) so imported resolutions (source = CTRM) can be linked to
 * their issuing organism via shortName 'CTRM'.
 *
 * The only prior reference to this body lived in an archived MariaDB migration
 * under its outdated name ("Consejo de Transparencia…"); the current legal name
 * is "Comisionado de Transparencia…", which is what the portal API reports.
 *
 * Fully idempotent: inserts the row only when absent, repairs a stale name/slug
 * and backfills the autonomous_community link when it can be resolved. Re-running
 * is a no-op.
 */
final class Version20260618120000 extends AbstractMigration
{
    private const NAME = 'Comisionado de Transparencia de la Región de Murcia';
    private const SHORT_NAME = 'CTRM';
    private const SLUG = 'comisionado-transparencia-region-murcia';
    private const CCAA = 'Región de Murcia';

    public function getDescription(): string
    {
        return 'Seed ComplaintOrganism CTRM (Comisionado de Transparencia de la Región de Murcia) for the CTRM resolution importer.';
    }

    public function up(Schema $schema): void
    {
        $name = self::NAME;
        $shortName = self::SHORT_NAME;
        $slug = self::SLUG;
        $ccaa = self::CCAA;

        // 1) Insert the organism if no row with shortName 'CTRM' exists yet.
        $this->addSql(<<<SQL
            INSERT INTO complaint_organism (id, name, short_name, slug, autonomous_community_id)
            SELECT gen_random_uuid(),
                   '$name',
                   '$shortName',
                   '$slug',
                   (SELECT id FROM autonomous_community WHERE name = '$ccaa' LIMIT 1)
            WHERE NOT EXISTS (SELECT 1 FROM complaint_organism WHERE short_name = '$shortName')
        SQL);

        // 2) Repair a stale name left over from the old "Consejo…" naming.
        $this->addSql(<<<SQL
            UPDATE complaint_organism
            SET name = '$name'
            WHERE short_name = '$shortName'
              AND name <> '$name'
        SQL);

        // 3) Backfill the slug when missing (slug is UNIQUE; guard against clashes).
        $this->addSql(<<<SQL
            UPDATE complaint_organism
            SET slug = '$slug'
            WHERE short_name = '$shortName'
              AND (slug IS NULL OR slug = '')
              AND NOT EXISTS (SELECT 1 FROM complaint_organism WHERE slug = '$slug')
        SQL);

        // 4) Backfill the CCAA link when it was null and the CCAA now resolves.
        $this->addSql(<<<SQL
            UPDATE complaint_organism
            SET autonomous_community_id = (SELECT id FROM autonomous_community WHERE name = '$ccaa' LIMIT 1)
            WHERE short_name = '$shortName'
              AND autonomous_community_id IS NULL
              AND EXISTS (SELECT 1 FROM autonomous_community WHERE name = '$ccaa')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: removing the organism would orphan any resolutions linked to it.
    }
}
