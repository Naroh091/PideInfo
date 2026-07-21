<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rediseño del workflow de `AccessRequest`: `status` deja de contener la
 * DECISIÓN. Las cuatro posiciones terminales (`granted_completed`,
 * `partially_granted`, `denied`, `inadmitted`) se colapsan en una única
 * posición `finished` («Finalizada»); la decisión vive ya solo en
 * `resolution_result`.
 *
 * 1) Rellena `resolution_result` desde el status-decisión SOLO si está NULL
 *    (respeta correcciones manuales previas).
 * 2) Colapsa las cuatro posiciones en `finished`.
 *
 * Idempotente: en una segunda pasada no queda ninguna fila con las posiciones
 * viejas, así que ambos UPDATE son no-op.
 */
final class Version20260721120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collapse decision statuses into `finished`; decision lives in resolution_result';
    }

    public function up(Schema $schema): void
    {
        // 1) Backfill de la decisión antes de colapsar (solo si falta).
        $this->addSql("UPDATE access_request SET resolution_result = 'partially_granted' WHERE status = 'partially_granted' AND resolution_result IS NULL");
        $this->addSql("UPDATE access_request SET resolution_result = 'denied'            WHERE status = 'denied'            AND resolution_result IS NULL");
        $this->addSql("UPDATE access_request SET resolution_result = 'inadmitted'        WHERE status = 'inadmitted'        AND resolution_result IS NULL");
        $this->addSql("UPDATE access_request SET resolution_result = 'granted'           WHERE status = 'granted_completed' AND resolution_result IS NULL");

        // 2) Colapsar las cuatro posiciones terminales en `finished`.
        $this->addSql("UPDATE access_request SET status = 'finished' WHERE status IN ('granted_completed', 'partially_granted', 'denied', 'inadmitted')");
    }

    public function down(Schema $schema): void
    {
        // Reverso best-effort: reexpande `finished` según la decisión registrada.
        // Los `finished` cuya decisión no encaje (p. ej. silence) se quedan como
        // están — no había posición equivalente en el modelo viejo.
        $this->addSql("UPDATE access_request SET status = 'granted_completed' WHERE status = 'finished' AND resolution_result = 'granted'");
        $this->addSql("UPDATE access_request SET status = 'partially_granted' WHERE status = 'finished' AND resolution_result = 'partially_granted'");
        $this->addSql("UPDATE access_request SET status = 'denied'            WHERE status = 'finished' AND resolution_result = 'denied'");
        $this->addSql("UPDATE access_request SET status = 'inadmitted'        WHERE status = 'finished' AND resolution_result = 'inadmitted'");
    }
}
