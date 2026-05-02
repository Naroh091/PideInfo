<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds the two AGE ámbitos that were missing from public_body so
 * `app:public-bodies:assign-portal-amb` can map them:
 *   - 101504 → Casa Real
 *   - 101505 → Secretaría de Estado de la Seguridad Social y Pensiones
 *
 * Both are state-level bodies submittable through the AGE Portal de
 * Transparencia wizard (idProc=133628). Idempotent via ON CONFLICT (slug).
 */
final class Version20260502150000 extends AbstractMigration
{
    private const PORTAL_URL = 'https://transparencia.sede.gob.es';

    private const ROWS = [
        [
            'name' => 'Casa Real',
            'slug' => 'casa-real',
            'amb' => 101504,
        ],
        [
            'name' => 'Secretaría de Estado de la Seguridad Social y Pensiones',
            'slug' => 'secretaria-de-estado-de-la-seguridad-social-y-pensiones',
            'amb' => 101505,
        ],
    ];

    public function getDescription(): string
    {
        return 'Seed Casa Real (idAmb 101504) and Secretaría de Estado de la Seguridad Social y Pensiones (idAmb 101505) into public_body.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROWS as $row) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO public_body (id, name, slug, level, transparency_portal_url, transparency_portal_amb_id)
                    VALUES (gen_random_uuid(), :name, :slug, 'state', :url, :amb)
                    ON CONFLICT (slug) DO NOTHING
                SQL,
                [
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'url' => self::PORTAL_URL,
                    'amb' => $row['amb'],
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROWS as $row) {
            $this->addSql(
                'DELETE FROM public_body WHERE slug = :slug AND transparency_portal_amb_id = :amb',
                ['slug' => $row['slug'], 'amb' => $row['amb']]
            );
        }
    }
}
