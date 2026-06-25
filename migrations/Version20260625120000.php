<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las novedades (usage_hint) pasan de almacenar Markdown a HTML, ya que el
 * admin ahora las edita con Trix y el frontend las renderiza con |raw.
 * Convierte las dos novedades sembradas en Version20260602130000 de Markdown
 * a su equivalente HTML.
 *
 * Idempotente: solo actúa sobre filas que aún contienen marcadores Markdown
 * (`content LIKE '%**%'`), por lo que reejecutarla o aplicarla sobre filas ya
 * editadas en HTML no tiene efecto.
 */
final class Version20260625120000 extends AbstractMigration
{
    private const HINT_HEARING_ID = '019e892c-229f-7b97-bc11-e86ba5ccd876';
    private const HINT_SECTIONS_ID = '019e892c-229f-7c47-bc11-e86ba619d241';

    public function getDescription(): string
    {
        return 'Convert seeded usage_hint content from Markdown to HTML (Trix)';
    }

    public function up(Schema $schema): void
    {
        $hearingHtml = 'Cuando el organismo de transparencia abre un <strong>trámite de audiencia</strong> '
            .'en una de tus reclamaciones, PideInfo detecta el documento, calcula automáticamente el plazo '
            .'para presentar alegaciones (en días hábiles o naturales) y te lo muestra en el detalle de la '
            .'solicitud y en las alertas de plazos de este panel.';

        $sectionsHtml = 'Ya puedes consultar <strong>todos tus documentos importados</strong> en la nueva '
            .'sección <em>Documentos</em> (desplegable «Solicitudes» del menú) y gestionar los '
            .'<strong>emails recibidos en tu buzón virtual</strong> desde la nueva vista <em>Comunicaciones</em>: '
            .'vincúlalos a tus solicitudes, descárgalos o elimínalos.';

        $this->addSql(
            "UPDATE usage_hint SET content = :content WHERE id = :id AND content LIKE '%**%'",
            ['content' => $hearingHtml, 'id' => self::HINT_HEARING_ID]
        );

        $this->addSql(
            "UPDATE usage_hint SET content = :content WHERE id = :id AND content LIKE '%**%'",
            ['content' => $sectionsHtml, 'id' => self::HINT_SECTIONS_ID]
        );
    }

    public function down(Schema $schema): void
    {
        $hearingMd = 'Cuando el organismo de transparencia abre un **trámite de audiencia** en una de tus '
            .'reclamaciones, PideInfo detecta el documento, calcula automáticamente el plazo para presentar '
            .'alegaciones (en días hábiles o naturales) y te lo muestra en el detalle de la solicitud y en '
            .'las alertas de plazos de este panel.';

        $sectionsMd = 'Ya puedes consultar **todos tus documentos importados** en la nueva sección '
            .'*Documentos* (desplegable «Solicitudes» del menú) y gestionar los **emails recibidos en tu '
            .'buzón virtual** desde la nueva vista *Comunicaciones*: vincúlalos a tus solicitudes, '
            .'descárgalos o elimínalos.';

        $this->addSql(
            "UPDATE usage_hint SET content = :content WHERE id = :id AND content LIKE '%<strong>%'",
            ['content' => $hearingMd, 'id' => self::HINT_HEARING_ID]
        );

        $this->addSql(
            "UPDATE usage_hint SET content = :content WHERE id = :id AND content LIKE '%<strong>%'",
            ['content' => $sectionsMd, 'id' => self::HINT_SECTIONS_ID]
        );
    }
}
