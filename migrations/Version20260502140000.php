<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds transparency_portal_amb_id to public_body to map a body to its
 * "idAmb" code in the AGE Portal de Transparencia wizard URL
 * (/procedimiento/formulario?idProc=133628&idAmb={ID}).
 */
final class Version20260502140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public_body.transparency_portal_amb_id (nullable int) for AGE wizard mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE public_body
                ADD COLUMN IF NOT EXISTS transparency_portal_amb_id INT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE public_body
                DROP COLUMN IF EXISTS transparency_portal_amb_id
        SQL);
    }
}
