<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Legal framework (legalize-es).
 *
 * Two-tier design:
 *  - `legal_norm` holds the WHOLE catalogue of the legalize-es repo (tens of thousands of
 *    norms), populated from each file's YAML frontmatter. It is what lets `find_law` turn
 *    "Ley de Bases del Régimen Local" into BOE-A-1985-5392.
 *  - `legal_article` holds the articulado of the ~50 *tracked* norms only (see TrackedNorms).
 *    It is the source of truth for the Elasticsearch `laws` index. Anything not tracked is
 *    parsed on the fly from /var/data/legalize.
 *
 * `legal_sync_state` stores the last synced `main` SHA so the daily pull can diff
 * (`git diff --name-only <old> <new>`) instead of re-hashing tens of thousands of files.
 *
 * Also: ApplicableLaw.boe_id (deterministic link between the resolved transparency law and
 * its text) and User.requester_capacity (the capacity the right of access is exercised in —
 * a concejal has a *different*, more favourable regime: art. 77 LBRL + arts. 14-16 ROF).
 *
 * Idempotent: to_regclass guards on every CREATE TABLE, IF NOT EXISTS on every index and
 * column, and the sync-state seed is an INSERT ... WHERE NOT EXISTS.
 */
final class Version20260712120000 extends AbstractMigration
{
    /**
     * short_code => BOE id, for the rows we can map without ambiguity.
     *
     * Note LTAIPBG: despite the code looking autonomous, that row IS the state law
     * (`name` = "Ley 19/2013, de 9 de diciembre, de transparencia"). It is by far the most
     * important mapping here — it is the applicable law of most requests — so it is keyed by
     * what the database actually holds, not by what the code suggests.
     *
     * Deliberately left NULL, because the rows themselves are wrong and fixing them changes
     * deadlines (DeadlineCalculator) — that is a data PR of its own:
     *   - LILE  → "Ley 2/2016 de Instituciones Locales de Euskadi", which is not a
     *             transparency law at all.
     *   - LTCV  → "Ley 2/2015", repealed by the Ley 1/2022.
     *
     * País Vasco has no row here to fix: its transparency law is not in the BOE consolidated
     * corpus legalize-es is built from, so there is no BOE id to point at.
     *
     * LegalFrameworkComposer degrades gracefully when boe_id is NULL: no deterministic block,
     * the agent still has the tools.
     *
     * @var array<string, string>
     */
    private const APPLICABLE_LAW_BOE_IDS = [
        // The state law has TWO rows in applicable_law: `LTAIPBG` ("Ley 19/2013, de 9 de
        // diciembre, de transparencia") and `LTBG` ("Ley 19/2013 de transparencia"). Both are
        // in use by real requests. Mapping only one left the other with no legal framework at
        // all. Deduplicating them is a data PR of its own.
        'LTAIPBG' => 'BOE-A-2013-12887', // Ley 19/2013 — ESTATAL
        'LTBG'    => 'BOE-A-2013-12887', // Ley 19/2013 — ESTATAL (fila duplicada)
        'LTA'     => 'BOE-A-2014-7534',  // Andalucía
        'LTAPA'   => 'BOE-A-2015-5332',  // Aragón
        'LTBGGI'  => 'BOE-A-2018-14293', // Asturias — Ley 8/2018
        'LTAIPC'  => 'BOE-A-2015-1114',  // Canarias
        'LTAPC'   => 'BOE-A-2018-5393',  // Cantabria
        'LTBGCLM' => 'BOE-A-2017-1373',  // Castilla-La Mancha
        'LTPCCYL' => 'BOE-A-2015-3281',  // Castilla y León
        'LTC'     => 'BOE-A-2015-470',   // Cataluña
        'LGAE'    => 'BOE-A-2013-6050',  // Extremadura — Ley 4/2013 de Gobierno Abierto
        'LTBGG'   => 'BOE-A-2016-3190',  // Galicia
        'LTCM'    => 'BOE-A-2019-10102', // Madrid
        'LTPCM'   => 'BOE-A-2015-184',   // Murcia
        'LTGAN'   => 'BOE-A-2018-7642',  // Navarra — la fila ya apunta a la LF 5/2018 vigente
    ];

    public function getDescription(): string
    {
        return 'Legal framework: legalize-es catalogue (legal_norm), tracked articulado (legal_article), sync state, ApplicableLaw.boeId and User.requesterCapacity.';
    }

    public function up(Schema $schema): void
    {
        // Trigram matching on norm titles: users and the model say "ley de bases del régimen
        // local", never the official 90-character title.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // ---------------------------------------------------------------- legal_norm
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('legal_norm') IS NULL THEN
                CREATE TABLE legal_norm (
                    id UUID NOT NULL,
                    boe_id VARCHAR(40) NOT NULL,
                    jurisdiction VARCHAR(8) NOT NULL,
                    relative_path VARCHAR(255) NOT NULL,
                    title TEXT NOT NULL,
                    official_number VARCHAR(40) DEFAULT NULL,
                    norm_rank VARCHAR(60) DEFAULT NULL,
                    rank_code VARCHAR(20) DEFAULT NULL,
                    scope VARCHAR(40) DEFAULT NULL,
                    department VARCHAR(255) DEFAULT NULL,
                    status VARCHAR(30) DEFAULT NULL,
                    consolidation_status VARCHAR(60) DEFAULT NULL,
                    publication_date DATE DEFAULT NULL,
                    enactment_date DATE DEFAULT NULL,
                    last_updated DATE DEFAULT NULL,
                    url_eli VARCHAR(500) DEFAULT NULL,
                    url_html_consolidada VARCHAR(500) DEFAULT NULL,
                    url_pdf VARCHAR(500) DEFAULT NULL,
                    subjects JSONB DEFAULT NULL,
                    tracked BOOLEAN NOT NULL DEFAULT FALSE,
                    content_hash VARCHAR(64) DEFAULT NULL,
                    article_count INT NOT NULL DEFAULT 0,
                    parse_status VARCHAR(20) DEFAULT NULL,
                    articles_indexed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    search_vector tsvector GENERATED ALWAYS AS (
                        setweight(to_tsvector('spanish', coalesce(title, '')), 'A') ||
                        setweight(to_tsvector('spanish', coalesce(official_number, '')), 'A') ||
                        setweight(to_tsvector('spanish', coalesce(department, '')), 'C')
                    ) STORED,
                    PRIMARY KEY (id)
                );
            END IF; END $$
        SQL);

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_legal_norm_boe ON legal_norm (boe_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_norm_jurisdiction ON legal_norm (jurisdiction)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_norm_tracked ON legal_norm (boe_id) WHERE tracked');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_norm_number_rank ON legal_norm (official_number, norm_rank)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_norm_search ON legal_norm USING GIN (search_vector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_norm_title_trgm ON legal_norm USING GIN (lower(title) gin_trgm_ops)');

        // ------------------------------------------------------------- legal_article
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('legal_article') IS NULL THEN
                CREATE TABLE legal_article (
                    id UUID NOT NULL,
                    norm_id UUID NOT NULL,
                    boe_id VARCHAR(40) NOT NULL,
                    anchor VARCHAR(80) NOT NULL,
                    kind VARCHAR(24) NOT NULL,
                    number VARCHAR(24) DEFAULT NULL,
                    number_int INT DEFAULT NULL,
                    number_suffix VARCHAR(12) DEFAULT NULL,
                    position INT NOT NULL,
                    heading VARCHAR(500) DEFAULT NULL,
                    breadcrumb TEXT DEFAULT NULL,
                    breadcrumb_json JSONB DEFAULT NULL,
                    content TEXT NOT NULL,
                    content_notes TEXT DEFAULT NULL,
                    repealed BOOLEAN NOT NULL DEFAULT FALSE,
                    char_count INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY (id)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE legal_article
                    ADD CONSTRAINT fk_legal_article_norm FOREIGN KEY (norm_id)
                    REFERENCES legal_norm (id) ON DELETE CASCADE;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_legal_article_norm_anchor ON legal_article (norm_id, anchor)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_article_boe_position ON legal_article (boe_id, position)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_legal_article_number ON legal_article (boe_id, number_int, number_suffix)');
        // Feeds DoctrineLegislationSearch, the fallback when Elasticsearch is down.
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_legal_article_fts ON legal_article
                USING GIN (to_tsvector('spanish', coalesce(heading, '') || ' ' || content))
        SQL);

        // ---------------------------------------------------------- legal_sync_state
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('legal_sync_state') IS NULL THEN
                CREATE TABLE legal_sync_state (
                    id SMALLINT NOT NULL,
                    head_sha VARCHAR(64) DEFAULT NULL,
                    synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    PRIMARY KEY (id),
                    CONSTRAINT legal_sync_state_singleton CHECK (id = 1)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO legal_sync_state (id, head_sha, synced_at)
            SELECT 1, NULL, NULL
            WHERE NOT EXISTS (SELECT 1 FROM legal_sync_state WHERE id = 1)
        SQL);

        // ------------------------------------------------------ applicable_law.boe_id
        $this->addSql('ALTER TABLE applicable_law ADD COLUMN IF NOT EXISTS boe_id VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_applicable_law_boe ON applicable_law (boe_id)');

        foreach (self::APPLICABLE_LAW_BOE_IDS as $shortCode => $boeId) {
            $this->addSql(
                'UPDATE applicable_law SET boe_id = :boe WHERE short_code = :code AND boe_id IS NULL',
                ['boe' => $boeId, 'code' => $shortCode],
            );
        }

        // -------------------------------------------------- user.requester_capacity
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS requester_capacity VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS requester_capacity_detail VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS legal_article');
        $this->addSql('DROP TABLE IF EXISTS legal_norm');
        $this->addSql('DROP TABLE IF EXISTS legal_sync_state');
        $this->addSql('ALTER TABLE applicable_law DROP COLUMN IF EXISTS boe_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS requester_capacity');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS requester_capacity_detail');
    }
}
