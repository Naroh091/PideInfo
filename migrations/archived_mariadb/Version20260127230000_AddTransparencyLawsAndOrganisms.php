<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add transparency laws for remaining autonomous communities and complaint organisms.
 *
 * This migration:
 * 1. Inserts 13 ComplaintOrganism records (transparency councils/commissions)
 * 2. Inserts 9 missing ApplicableLaw records for communities not yet covered
 * 3. Updates all applicable_law records to link to their complaint organisms
 */
final class Version20260127230000_AddTransparencyLawsAndOrganisms extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add transparency laws for remaining autonomous communities and complaint organisms';
    }

    public function up(Schema $schema): void
    {
        // =====================================================================
        // 1. INSERT COMPLAINT ORGANISMS
        // =====================================================================

        // CTBG - Consejo de Transparencia y Buen Gobierno (State, Asturias, Baleares, Cantabria, Extremadura, La Rioja, Ceuta, Melilla)
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia y Buen Gobierno',
            'CTBG',
            'https://consejodetransparencia.es',
            'https://consejodetransparencia.es/reclamaciones'
        )");

        // CTPDA - Consejo de Transparencia y Protección de Datos de Andalucía
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia y Protección de Datos de Andalucía',
            'CTPDA',
            'https://www.ctpdandalucia.es',
            'https://www.ctpdandalucia.es/reclamaciones'
        )");

        // CTA - Consejo de Transparencia de Aragón
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia de Aragón',
            'CTA',
            'https://transparencia.aragon.es/CTAR',
            NULL
        )");

        // GAIP - Comissió de Garantia del Dret d'Accés a la Informació Pública (Cataluña)
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Comissió de Garantia del Dret d''Accés a la Informació Pública',
            'GAIP',
            'https://www.gaip.cat',
            'https://www.gaip.cat/reclamacions'
        )");

        // CVAIP - Comisión Vasca de Acceso a la Información Pública
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Comisión Vasca de Acceso a la Información Pública',
            'CVAIP',
            'https://www.gardena.euskadi.eus',
            NULL
        )");

        // CVT - Consell de Transparència, Accés a la Informació Pública i Bon Govern (Comunitat Valenciana)
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consell de Transparència, Accés a la Informació Pública i Bon Govern',
            'CVT',
            'https://www.conselltransparencia.gva.es',
            NULL
        )");

        // CTPDCM - Consejo de Transparencia y Participación de la Comunidad de Madrid
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia y Participación de la Comunidad de Madrid',
            'CTPDCM',
            'https://www.comunidad.madrid/transparencia',
            NULL
        )");

        // CTG - Comisión de Transparencia de Galicia
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Comisión de Transparencia de Galicia',
            'CTG',
            'https://www.comisiondatransparencia.gal',
            NULL
        )");

        // CTRM - Consejo de Transparencia de la Región de Murcia
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia de la Región de Murcia',
            'CTRM',
            'https://consejotransparencia-rm.es',
            NULL
        )");

        // CTN - Consejo de Transparencia de Navarra
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo de Transparencia de Navarra',
            'CTN',
            'https://www.gobiernoabierto.navarra.es',
            NULL
        )");

        // CTCAN - Comisionado de Transparencia y Acceso a la Información Pública (Canarias)
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Comisionado de Transparencia y Acceso a la Información Pública',
            'CTCAN',
            'https://transparenciacanarias.org',
            NULL
        )");

        // CTCYL - Comisionado de Transparencia de Castilla y León
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Comisionado de Transparencia de Castilla y León',
            'CTCYL',
            'https://www.ctcyl.es',
            NULL
        )");

        // CTCLM - Consejo Regional de Transparencia y Buen Gobierno (Castilla-La Mancha)
        $this->addSql("INSERT INTO complaint_organism (id, name, short_name, url, complaint_form_url) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Consejo Regional de Transparencia y Buen Gobierno',
            'CTCLM',
            'https://www.consejotransparenciaclm.es',
            NULL
        )");

        // =====================================================================
        // 2. INSERT MISSING APPLICABLE LAWS
        // =====================================================================

        // Aragón - Ley 8/2015 (LTAPA)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 8/2015, de 25 de marzo, de Transparencia de la Actividad Pública y Participación Ciudadana de Aragón',
            'LTAPA',
            (SELECT id FROM autonomous_community WHERE code = 'ARA'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTA')
        )");

        // Canarias - Ley 12/2014 (LTAIPC)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 12/2014, de 26 de diciembre, de transparencia y de acceso a la información pública',
            'LTAIPC',
            (SELECT id FROM autonomous_community WHERE code = 'CAN'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTCAN')
        )");

        // Cantabria - Ley 1/2018 (LTAPC)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 1/2018, de 21 de marzo, de Transparencia de la Actividad Pública',
            'LTAPC',
            (SELECT id FROM autonomous_community WHERE code = 'CNT'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTBG')
        )");

        // Castilla y León - Ley 3/2015 (LTPCCYL)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 3/2015, de 4 de marzo, de Transparencia y Participación Ciudadana de Castilla y León',
            'LTPCCYL',
            (SELECT id FROM autonomous_community WHERE code = 'CYL'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTCYL')
        )");

        // Extremadura - Ley 4/2013 (LGAE)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 4/2013, de 21 de mayo, de Gobierno Abierto de Extremadura',
            'LGAE',
            (SELECT id FROM autonomous_community WHERE code = 'EXT'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTBG')
        )");

        // Murcia - Ley 12/2014 (LTPCM)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley 12/2014, de 16 de diciembre, de Transparencia y Participación Ciudadana de la Región de Murcia',
            'LTPCM',
            (SELECT id FROM autonomous_community WHERE code = 'MUR'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTRM')
        )");

        // Navarra - Ley Foral 11/2012 (LTGAN)
        $this->addSql("INSERT INTO applicable_law (
            id, name, short_code, autonomous_community_id,
            response_deadline_value, response_deadline_unit,
            extension_value, extension_unit, max_extensions,
            complaint_deadline_days, compliance_after_complaint_days,
            silence_is_positive, notes, official_url, complaint_organism_id
        ) VALUES (
            UNHEX(REPLACE(UUID(), '-', '')),
            'Ley Foral 11/2012, de 21 de junio, de la Transparencia y del Gobierno Abierto',
            'LTGAN',
            (SELECT id FROM autonomous_community WHERE code = 'NAV'),
            1, 'months',
            1, 'months', 1,
            30, 10,
            0, NULL, NULL,
            (SELECT id FROM complaint_organism WHERE short_name = 'CTN')
        )");

        // =====================================================================
        // 3. UPDATE EXISTING LAWS WITH COMPLAINT ORGANISM
        // =====================================================================

        // State law (LTAIPBG) -> CTBG
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CTBG'
        ) WHERE short_code = 'LTAIPBG'");

        // Andalucía (LTA) -> CTPDA
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CTPDA'
        ) WHERE short_code = 'LTA'");

        // Cataluña (LTC) -> GAIP
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'GAIP'
        ) WHERE short_code = 'LTC'");

        // País Vasco (LILE) -> CVAIP
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CVAIP'
        ) WHERE short_code = 'LILE'");

        // Comunitat Valenciana (LTCV) -> CVT
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CVT'
        ) WHERE short_code = 'LTCV'");

        // Madrid (LTCM) -> CTPDCM
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CTPDCM'
        ) WHERE short_code = 'LTCM'");

        // Galicia (LTBGG) -> CTG
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = (
            SELECT id FROM complaint_organism WHERE short_name = 'CTG'
        ) WHERE short_code = 'LTBGG'");
    }

    public function down(Schema $schema): void
    {
        // Remove complaint_organism_id from existing laws
        $this->addSql("UPDATE applicable_law SET complaint_organism_id = NULL WHERE short_code IN (
            'LTAIPBG', 'LTA', 'LTC', 'LILE', 'LTCV', 'LTCM', 'LTBGG'
        )");

        // Delete new laws
        $this->addSql("DELETE FROM applicable_law WHERE short_code IN (
            'LTBGGI', 'LTBGCLM', 'LTAPA', 'LTAIPC', 'LTAPC', 'LTPCCYL', 'LGAE', 'LTPCM', 'LTGAN'
        )");

        // Delete complaint organisms
        $this->addSql("DELETE FROM complaint_organism WHERE short_name IN (
            'CTBG', 'CTPDA', 'CTA', 'GAIP', 'CVAIP', 'CVT', 'CTPDCM', 'CTG', 'CTRM', 'CTN', 'CTCAN', 'CTCYL', 'CTCLM'
        )");
    }
}
