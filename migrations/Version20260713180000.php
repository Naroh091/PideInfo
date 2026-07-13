<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill de resolution_result para solicitudes cuyo status ya encierra la
 * decisión pero que se escribieron por caminos que no la inferían:
 *
 *  - `delayed` fijado por los jobs de expiración (setStatus directo, sin pasar
 *    por AccessRequestManager::changeStatus, que es quien infiere `silence`);
 *  - `partially_granted` / `inadmitted` fijados por el pipeline de documentos
 *    antes de que persistiera también el resultado ortogonal.
 *
 * Desde esta versión los tres caminos infieren el resultado al escribir, así
 * que esto solo repara datos históricos. Idempotente por construcción: el
 * WHERE exige `resolution_result IS NULL`. No se rellena `resolved_at` (la
 * fecha real de notificación no se puede reconstruir desde aquí).
 */
final class Version20260713180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill access_request.resolution_result from statuses that imply the decision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE access_request SET resolution_result = 'silence'
             WHERE status = 'delayed' AND resolution_result IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE access_request SET resolution_result = 'partially_granted'
             WHERE status = 'partially_granted' AND resolution_result IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE access_request SET resolution_result = 'inadmitted'
             WHERE status = 'inadmitted' AND resolution_result IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: un resultado inferido en el backfill es indistinguible de uno
        // escrito explícitamente por el pipeline; revertirlo borraría datos buenos.
        $this->skipIf(true, 'Backfill de datos, sin reversa segura');
    }
}
