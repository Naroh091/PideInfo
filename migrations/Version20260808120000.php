<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las fechas de una solicitud (fecha de envío y plazo de respuesta) sólo se
 * calculan cuando la solicitud pasa al estado "enviada". Mientras está en
 * borrador, ambas columnas pueden ser NULL.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make sent_at and deadline_at nullable on access_request for draft support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ALTER sent_at DROP NOT NULL');
        $this->addSql('ALTER TABLE access_request ALTER deadline_at DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE access_request SET sent_at = created_at WHERE sent_at IS NULL');
        $this->addSql('UPDATE access_request SET deadline_at = sent_at WHERE deadline_at IS NULL AND sent_at IS NOT NULL');
        $this->addSql('ALTER TABLE access_request ALTER sent_at SET NOT NULL');
        $this->addSql('ALTER TABLE access_request ALTER deadline_at SET NOT NULL');
    }
}