<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `dir3_code` to `complaint_organism` and populates it for the 10
 * regional transparency councils that accept complaints via REG
 * (Registro Electrónico General, redsara.es).
 *
 * The DIR3 code identifies the destination unit in the REG autocomplete.
 * When set, the organism supports TYPE_PRESENT_COMPLAINT_REG submissions
 * via `ComplaintOrganism::supportsRegSubmission()`.
 *
 * Fully idempotent: the ALTER is guarded, and every UPDATE checks that the
 * dir3_code is not already set to avoid overwriting manual corrections.
 */
final class Version20260620180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dir3_code to complaint_organism and populate it for 10 regional transparency councils (REG submission channel).';
    }

    public function up(Schema $schema): void
    {
        // 1) Add the column if it does not exist yet.
        $this->addSql(<<<'SQL'
            ALTER TABLE complaint_organism
            ADD COLUMN IF NOT EXISTS dir3_code VARCHAR(20) DEFAULT NULL
        SQL);

        // 2) Populate dir3_code for each supported organism.
        //    Uses short_name as the stable identifier (unique in the table).
        //    Each UPDATE is guarded: only fires when dir3_code is not already set,
        //    so re-running the migration is a no-op.
        $organisms = [
            'CTG'   => 'A12019988',
            'CTRM'  => 'A14022345',
            'CTCAN' => 'I00000907',
            'GAIP'  => 'A09021957',
            'CTR'   => 'I00004460',
            'CTPD'  => 'A13049492',
            'CTPDA' => 'A01018825',
            'CVT'   => 'A10033253',
            'CTN'   => 'A15031131',
            'CVAIP' => 'A16021890',
            'CTCYL' => 'I00000423',
        ];

        foreach ($organisms as $shortName => $dir3Code) {
            $this->addSql(<<<SQL
                UPDATE complaint_organism
                SET dir3_code = '$dir3Code'
                WHERE short_name = '$shortName'
                  AND (dir3_code IS NULL OR dir3_code = '')
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_organism DROP COLUMN IF EXISTS dir3_code');
    }
}
