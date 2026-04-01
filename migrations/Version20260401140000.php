<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image to complaint_organism and autonomous_community, add direct relation complaint_organism → autonomous_community';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_organism ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE complaint_organism ADD COLUMN IF NOT EXISTS autonomous_community_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_complaint_organism_ac ON complaint_organism (autonomous_community_id)');

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                ALTER TABLE complaint_organism
                    ADD CONSTRAINT fk_complaint_organism_ac
                    FOREIGN KEY (autonomous_community_id) REFERENCES autonomous_community(id);
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END $$
        SQL);

        $this->addSql('ALTER TABLE autonomous_community ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_organism DROP CONSTRAINT IF EXISTS fk_complaint_organism_ac');
        $this->addSql('DROP INDEX IF EXISTS idx_complaint_organism_ac');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS autonomous_community_id');
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS image');
        $this->addSql('ALTER TABLE autonomous_community DROP COLUMN IF EXISTS image');
    }
}
