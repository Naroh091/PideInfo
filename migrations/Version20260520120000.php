<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índice único parcial: como mucho UNA agent_task no terminal
 * (pending/claimed/in_progress) por (access_request_id, type).
 *
 * Backstop universal contra el doble despacho concurrente (V2): si dos
 * peticiones intentan crear sendas tareas para la misma solicitud y canal a
 * la vez, la segunda INSERT falla en BD. Las tareas con access_request_id
 * NULL (no asociadas a una solicitud) quedan fuera del índice.
 *
 * Idempotente: CREATE/DROP INDEX IF [NOT] EXISTS.
 */
final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial unique index: at most one non-terminal agent_task per (access_request_id, type).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_agent_task_active_per_request
            ON agent_task (access_request_id, type)
            WHERE status IN ('pending', 'claimed', 'in_progress')
              AND access_request_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_agent_task_active_per_request');
    }
}
