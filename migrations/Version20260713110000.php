<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Judgments: the case law that shapes the right of access, imported from the CTBG recursos
 * listing (and later CENDOJ / manual upload).
 *
 * The `judgment_resolution` join table is the piece with product value: a resolution annulled
 * by a final judgment must never be cited as favourable precedent, and this is what lets
 * ResolutionRetriever know.
 *
 * ECLI gets a PARTIAL unique index (WHERE ecli IS NOT NULL): it cannot be the ingest key
 * because the XLSX does not publish it — it is only known after analysing the PDF.
 *
 * Idempotent, per CLAUDE.md: to_regclass guards, IF NOT EXISTS everywhere.
 */
final class Version20260713110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Judgments (sentencias) + judgment_resolution link table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('judgment') IS NULL THEN
                CREATE TABLE judgment (
                    id UUID NOT NULL,
                    reference_number VARCHAR(60) NOT NULL,
                    source VARCHAR(30) NOT NULL,
                    court VARCHAR(10) NOT NULL,
                    court_number INT DEFAULT NULL,
                    court_name VARCHAR(120) DEFAULT NULL,
                    chamber VARCHAR(60) DEFAULT NULL,
                    instance VARCHAR(24) NOT NULL,
                    judgment_number VARCHAR(30) DEFAULT NULL,
                    ecli VARCHAR(40) DEFAULT NULL,
                    roj VARCHAR(40) DEFAULT NULL,
                    judgment_date DATE DEFAULT NULL,
                    subject TEXT DEFAULT NULL,
                    appellant VARCHAR(255) DEFAULT NULL,
                    appellant_type VARCHAR(20) DEFAULT NULL,
                    representation VARCHAR(120) DEFAULT NULL,
                    outcome VARCHAR(30) DEFAULT NULL,
                    resolution_effect VARCHAR(30) DEFAULT NULL,
                    transparency_stance VARCHAR(20) DEFAULT NULL,
                    summary TEXT DEFAULT NULL,
                    keypoints JSONB DEFAULT NULL,
                    doctrine JSONB DEFAULT NULL,
                    interpreted_articles JSONB DEFAULT NULL,
                    keywords JSONB DEFAULT NULL,
                    topics JSONB DEFAULT NULL,
                    full_text TEXT DEFAULT NULL,
                    pdf_storage_path VARCHAR(255) DEFAULT NULL,
                    source_url VARCHAR(500) DEFAULT NULL,
                    needs_browser BOOLEAN NOT NULL DEFAULT FALSE,
                    is_final BOOLEAN NOT NULL DEFAULT FALSE,
                    final_date DATE DEFAULT NULL,
                    challenged_resolution_refs JSONB DEFAULT NULL,
                    source_metadata JSONB DEFAULT NULL,
                    reviewed_judgment_id UUID DEFAULT NULL,
                    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY (id)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE judgment
                    ADD CONSTRAINT fk_judgment_reviewed FOREIGN KEY (reviewed_judgment_id)
                    REFERENCES judgment (id) ON DELETE SET NULL;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_judgment_reference_source ON judgment (reference_number, source)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_judgment_ecli ON judgment (ecli) WHERE ecli IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_judgment_court ON judgment (court)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_judgment_stance ON judgment (transparency_stance)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_judgment_final ON judgment (is_final)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_judgment_reviewed ON judgment (reviewed_judgment_id)');

        $this->addSql(<<<'SQL'
            DO $$ BEGIN IF to_regclass('judgment_resolution') IS NULL THEN
                CREATE TABLE judgment_resolution (
                    judgment_id UUID NOT NULL,
                    resolution_id UUID NOT NULL,
                    PRIMARY KEY (judgment_id, resolution_id)
                );
            END IF; END $$
        SQL);

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE judgment_resolution
                    ADD CONSTRAINT fk_jr_judgment FOREIGN KEY (judgment_id)
                    REFERENCES judgment (id) ON DELETE CASCADE;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                ALTER TABLE judgment_resolution
                    ADD CONSTRAINT fk_jr_resolution FOREIGN KEY (resolution_id)
                    REFERENCES resolution (id) ON DELETE CASCADE;
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_jr_resolution ON judgment_resolution (resolution_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS judgment_resolution');
        $this->addSql('DROP TABLE IF EXISTS judgment');
    }
}
