<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla usage_hint (novedades descartables del panel) + columna
 * user.dismissed_hints (ids de novedades cerradas por el usuario).
 * Siembra las dos novedades iniciales: plazos de audiencia y las
 * secciones Documentos/Comunicaciones.
 *
 * Idempotente: CREATE TABLE/ADD COLUMN IF NOT EXISTS y seed con
 * INSERT ... WHERE NOT EXISTS (por id fijo).
 */
final class Version20260602130000 extends AbstractMigration
{
    private const HINT_HEARING_ID = '019e892c-229f-7b97-bc11-e86ba5ccd876';
    private const HINT_SECTIONS_ID = '019e892c-229f-7c47-bc11-e86ba619d241';

    public function getDescription(): string
    {
        return 'Create usage_hint table, user.dismissed_hints column, and seed initial hints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS usage_hint (
                id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                content TEXT NOT NULL,
                link_url VARCHAR(255) DEFAULT NULL,
                link_label VARCHAR(100) DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql("COMMENT ON COLUMN usage_hint.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN usage_hint.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS dismissed_hints JSON DEFAULT NULL');

        // Seed: plazos de audiencia (feature del 1 de junio de 2026)
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO usage_hint (id, title, content, link_url, link_label, is_active, created_at)
            SELECT '%s',
                   'Plazos de audiencia automáticos en reclamaciones',
                   'Cuando el organismo de transparencia abre un **trámite de audiencia** en una de tus reclamaciones, PideInfo detecta el documento, calcula automáticamente el plazo para presentar alegaciones (en días hábiles o naturales) y te lo muestra en el detalle de la solicitud y en las alertas de plazos de este panel.',
                   '/guias/reclamaciones',
                   'Ver la guía de reclamaciones',
                   TRUE,
                   '2026-06-01 12:00:00'
            WHERE NOT EXISTS (SELECT 1 FROM usage_hint WHERE id = '%s')
        SQL, self::HINT_HEARING_ID, self::HINT_HEARING_ID));

        // Seed: secciones Documentos y Comunicaciones
        $this->addSql(sprintf(<<<'SQL'
            INSERT INTO usage_hint (id, title, content, link_url, link_label, is_active, created_at)
            SELECT '%s',
                   'Nuevas secciones: Documentos y Comunicaciones',
                   'Ya puedes consultar **todos tus documentos importados** en la nueva sección *Documentos* (desplegable «Solicitudes» del menú) y gestionar los **emails recibidos en tu buzón virtual** desde la nueva vista *Comunicaciones*: vincúlalos a tus solicitudes, descárgalos o elimínalos.',
                   '/guias/documentos',
                   'Ver la guía de documentos',
                   TRUE,
                   '2026-06-02 12:00:00'
            WHERE NOT EXISTS (SELECT 1 FROM usage_hint WHERE id = '%s')
        SQL, self::HINT_SECTIONS_ID, self::HINT_SECTIONS_ID));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS dismissed_hints');
        $this->addSql('DROP TABLE IF EXISTS usage_hint');
    }
}
