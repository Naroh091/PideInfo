<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfills `public_body.autonomous_community_id` for legacy rows that pre-dated
 * the REG import and were never anchored to a CCAA. ≈27% of `level=local`
 * bodies (e.g. AYUNTAMIENTO DE MARBELLA) had `autonomous_community_id = NULL`,
 * which made {@see App\Service\Submission\ApplicableLawResolver} fall back to
 * the state-level Ley 19/2013 (LTAIPBG) instead of the regional law (e.g. LTA
 * 1/2014 for Andalucía).
 *
 * The rule mirrors the importer's anchoring policy: only commit a CCAA when
 * EVERY linked `reg_destination` row resolves to the same AutonomousCommunity.
 * If any unit disagrees (or none resolves) we leave the row alone so we never
 * mis-attribute a cross-territorial body.
 *
 * Idempotent: only updates rows where `autonomous_community_id IS NULL`, and
 * only when an unambiguous CCAA can be derived. Re-running is a no-op.
 */
final class Version20260516120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill public_body.autonomous_community_id from RegDestination.comunidad when all units agree (fixes ALR fallback to LTAIPBG for Andalusian/etc municipalities).';
    }

    public function up(Schema $schema): void
    {
        // Materialise a normalisation lookup that mirrors the runtime resolver
        // (lowercase + accent-strip + alias table). The CASE handles the two
        // CSV-only anomalies ("Ciudad Autónoma de…"); everything else matches
        // canonical autonomous_community.name on its own.
        $this->addSql(<<<'SQL'
            WITH
            -- 1) Map every reg_destination.comunidad to a canonical CCAA name.
            normalised AS (
                SELECT
                    rd.public_body_id,
                    CASE LOWER(unaccent(TRIM(rd.comunidad)))
                        WHEN 'ciudad autonoma de ceuta'   THEN 'Ceuta'
                        WHEN 'ciudad autonoma de melilla' THEN 'Melilla'
                        WHEN 'castilla la mancha'         THEN 'Castilla-La Mancha'
                        WHEN 'castilla y leon'            THEN 'Castilla y León'
                        WHEN 'comunidad valenciana'       THEN 'Comunitat Valenciana'
                        WHEN 'valenciana'                 THEN 'Comunitat Valenciana'
                        WHEN 'madrid'                     THEN 'Comunidad de Madrid'
                        WHEN 'navarra'                    THEN 'Comunidad Foral de Navarra'
                        WHEN 'islas baleares'             THEN 'Illes Balears'
                        WHEN 'baleares'                   THEN 'Illes Balears'
                        WHEN 'pais vasco'                 THEN 'País Vasco'
                        WHEN 'asturias'                   THEN 'Principado de Asturias'
                        WHEN 'murcia'                     THEN 'Región de Murcia'
                        WHEN 'region de murcia'           THEN 'Región de Murcia'
                        ELSE TRIM(rd.comunidad)
                    END AS canonical_name
                FROM reg_destination rd
                WHERE rd.comunidad IS NOT NULL AND TRIM(rd.comunidad) <> ''
            ),
            -- 2) Resolve canonical names against autonomous_community.
            resolved AS (
                SELECT n.public_body_id, ac.id AS ac_id
                FROM normalised n
                JOIN autonomous_community ac
                    ON LOWER(unaccent(ac.name)) = LOWER(unaccent(n.canonical_name))
            ),
            -- 3) Keep only bodies whose units all agree on a single CCAA.
            -- (Postgres has no MIN() for uuid, so we pluck the first element
            -- of the distinct array — guaranteed length 1 by the HAVING.)
            unanimous AS (
                SELECT public_body_id,
                       (array_agg(DISTINCT ac_id))[1] AS ac_id
                FROM resolved
                GROUP BY public_body_id
                HAVING COUNT(DISTINCT ac_id) = 1
            )
            UPDATE public_body pb
            SET autonomous_community_id = u.ac_id
            FROM unanimous u
            WHERE pb.id = u.public_body_id
              AND pb.autonomous_community_id IS NULL;
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: we cannot tell which rows were filled by this backfill vs.
        // by the importer. Reverting blindly would erase legitimate anchors.
    }
}
