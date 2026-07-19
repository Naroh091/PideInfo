<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Panel rediseñado: el resumen de actividad guarda, junto al HTML, los items
 * estructurados del LLM (estimaciones, plazos de alegaciones, silencios,
 * inadmisiones, caducidades…) que alimentan el sumario del hero y la tarjeta
 * «Necesita tu acción».
 *
 * Idempotente: ADD COLUMN IF NOT EXISTS / DROP COLUMN IF EXISTS.
 */
final class Version20260718230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Activity summary: structured items JSON column on "user"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS activity_summary_items JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS activity_summary_items');
    }
}
