<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document_date column, rename existing documents to <type> - <name>, populate dates from AI metadata';
    }

    /**
     * Map of document type DB values to their Spanish labels.
     */
    private const TYPE_LABELS = [
        'request' => 'Solicitud',
        'receipt' => 'Acuse de recibo',
        'processing_start' => 'Inicio de tramitación',
        'response' => 'Respuesta',
        'extension' => 'Prórroga',
        'redirection' => 'Traslado a otro órgano',
        'third_party_rights' => 'Afectación derechos terceros',
        'complaint' => 'Reclamación',
        'complaint_receipt' => 'Acuse recibo reclamación',
        'complaint_processing_start' => 'Inicio tramitación reclamación',
        'complaint_resolution' => 'Resolución CTBG',
        'alegaciones' => 'Alegaciones',
        'alegation_response' => 'Respuesta a alegaciones',
        'subsanacion' => 'Subsanación solicitada',
        'subsanacion_response' => 'Subsanación presentada',
        'audiencia' => 'Trámite de audiencia',
        'complaint_extension' => 'Ampliación de reclamación',
        'court' => 'Documento judicial',
    ];

    public function up(Schema $schema): void
    {
        // Add document_date column
        $this->addSql('ALTER TABLE document ADD document_date DATE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN document.document_date IS '(DC2Type:date_immutable)'");

        // Populate document_date from ai_metadata->>'documentDate' for processed documents
        $this->addSql("
            UPDATE document
            SET document_date = (ai_metadata->>'documentDate')::date
            WHERE processed = true
              AND ai_metadata IS NOT NULL
              AND ai_metadata->>'documentDate' IS NOT NULL
              AND ai_metadata->>'documentDate' != ''
        ");

        // Rename processed documents: prepend type label to filename
        // Skip 'unprocessed', 'other', and documents already prefixed
        foreach (self::TYPE_LABELS as $typeValue => $label) {
            // Update documents whose original_filename does NOT already start with the label
            $this->addSql("
                UPDATE document
                SET original_filename = :label || ' - ' || original_filename
                WHERE type = :type
                  AND processed = true
                  AND original_filename NOT LIKE :prefix
            ", [
                'label' => $label,
                'type' => $typeValue,
                'prefix' => $label . ' - %',
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Remove type label prefix from filenames
        foreach (self::TYPE_LABELS as $typeValue => $label) {
            $prefixLen = mb_strlen($label . ' - ') + 1; // +1 for 1-based SUBSTR
            $this->addSql("
                UPDATE document
                SET original_filename = SUBSTR(original_filename, :prefixLen)
                WHERE type = :type
                  AND original_filename LIKE :prefix
            ", [
                'prefixLen' => $prefixLen,
                'type' => $typeValue,
                'prefix' => $label . ' - %',
            ]);
        }

        $this->addSql('ALTER TABLE document DROP COLUMN document_date');
    }
}
