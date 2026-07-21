<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Redacción pública sin registro (`/redactar`):
 *
 *  - `access_request.user_id` pasa a nullable: los borradores anónimos viven
 *    sin dueño, referenciados solo en la sesión del visitante, hasta que se
 *    reclaman al registrarse/iniciar sesión o los purga
 *    `app:anonymous-drafts:purge`.
 *  - Inserta el organismo centinela «Organismo por determinar»
 *    (GenericDestination::PUBLIC_BODY_ID): nivel estatal, sin portal AMB y sin
 *    destinos REG, de modo que es estructuralmente insubmitible y nunca
 *    aparece en las búsquedas de destino.
 *
 * Idempotente: DROP NOT NULL es no-op si la columna ya es nullable, y el
 * INSERT usa ON CONFLICT DO NOTHING.
 */
final class Version20260714120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anonymous drafting: nullable access_request.user_id + sentinel PublicBody «Organismo por determinar»';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ALTER COLUMN user_id DROP NOT NULL');
        $this->addSql(<<<'SQL'
            INSERT INTO public_body (id, name, slug, level, imported_from_reg)
            VALUES ('00000000-0000-4000-8000-00000000feed', 'Organismo por determinar', 'organismo-por-determinar', 'state', false)
            ON CONFLICT (id) DO NOTHING
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM public_body
             WHERE id = '00000000-0000-4000-8000-00000000feed'
               AND NOT EXISTS (SELECT 1 FROM access_request WHERE public_body_id = '00000000-0000-4000-8000-00000000feed')
        SQL);
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM access_request WHERE user_id IS NULL) THEN
                    ALTER TABLE access_request ALTER COLUMN user_id SET NOT NULL;
                END IF;
            END $$
        SQL);
    }
}
