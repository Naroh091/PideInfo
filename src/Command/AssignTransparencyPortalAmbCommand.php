<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PublicBodyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Maps the 25 AGE "ámbitos" of the Portal de Transparencia wizard
 * (idAmb 101503-101527) onto their PublicBody rows by name.
 *
 * Source of truth for the mapping: app/docs/documentacion-procesos-envio/transparencia_age.md
 * (captured via Chrome MCP discovery on 2026-05-02). The wizard URL pattern is
 *   /procedimiento/formulario?idProc=133628&idAmb={ID}
 *
 * The command is idempotent: rows that already have transparency_portal_amb_id
 * set are skipped.
 */
#[AsCommand(
    name: 'app:public-bodies:assign-portal-amb',
    description: 'Assign the AGE Portal de Transparencia idAmb to each known PublicBody.',
)]
final class AssignTransparencyPortalAmbCommand extends Command
{
    private const PORTAL_URL = 'https://transparencia.sede.gob.es';

    /**
     * idAmb => canonical PublicBody.name.
     *
     * Names follow the labels rendered in the portal's left menu (dnt-vertical-menu-item.label).
     * If the name in DB diverges (e.g. older import without the "y" or trailing comma), the
     * mapping won't match and the row will be reported as a miss — that's intended, fix the
     * DB row rather than fuzzying the matching here.
     */
    private const AMBITOS = [
        101503 => 'Agencia Española de Protección de Datos',
        101504 => 'Casa Real',
        101505 => 'Secretaría de Estado de la Seguridad Social y Pensiones',
        101506 => 'Ministerio de Agricultura, Pesca y Alimentación',
        101507 => 'Ministerio de Asuntos Exteriores, Unión Europea y Cooperación',
        101508 => 'Ministerio de Ciencia, Innovación y Universidades',
        101509 => 'Ministerio de Cultura',
        101510 => 'Ministerio de Defensa',
        101511 => 'Ministerio de Derechos Sociales, Consumo y Agenda 2030',
        101512 => 'Ministerio de Economía, Comercio y Empresa',
        101513 => 'Ministerio de Educación, Formación Profesional y Deportes',
        101514 => 'Ministerio de Hacienda',
        101515 => 'Ministerio de Igualdad',
        101516 => 'Ministerio de Inclusión, Seguridad Social y Migraciones',
        101517 => 'Ministerio de Industria y Turismo',
        101518 => 'Ministerio del Interior',
        101519 => 'Ministerio de Juventud e Infancia',
        101520 => 'Ministerio de Política Territorial y Memoria Democrática',
        101521 => 'Ministerio de la Presidencia, Justicia, y Relaciones con las Cortes',
        101522 => 'Ministerio de Sanidad',
        101523 => 'Ministerio de Trabajo y Economía Social',
        101524 => 'Ministerio para la Transformación Digital y de la Función Pública',
        101525 => 'Ministerio para la Transición Ecológica y el Reto Demográfico',
        101526 => 'Ministerio de Transportes y Movilidad Sostenible',
        101527 => 'Ministerio de Vivienda y Agenda Urbana',
    ];

    public function __construct(
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be assigned without writing');
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Reassign even if a different value is already stored');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');

        $assigned = [];
        $skipped = [];
        $misses = [];

        foreach (self::AMBITOS as $idAmb => $canonicalName) {
            $body = $this->findByNameInsensitive($canonicalName);
            if ($body === null) {
                $misses[] = sprintf('%d → "%s"', $idAmb, $canonicalName);
                continue;
            }

            $current = $body->getTransparencyPortalAmbId();
            if ($current === $idAmb) {
                $skipped[] = sprintf('%d → %s', $idAmb, $body->getName());
                continue;
            }
            if ($current !== null && !$overwrite) {
                $skipped[] = sprintf('%d → %s (already has %d, use --overwrite to replace)', $idAmb, $body->getName(), $current);
                continue;
            }

            if (!$dryRun) {
                $body->setTransparencyPortalAmbId($idAmb);
                if ($body->getTransparencyPortalUrl() === null) {
                    $body->setTransparencyPortalUrl(self::PORTAL_URL);
                }
            }
            $assigned[] = sprintf('%d → %s', $idAmb, $body->getName());
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->section($dryRun ? 'DRY RUN (no writes)' : 'Done');
        $io->writeln(sprintf('Assigned: %d', count($assigned)));
        foreach ($assigned as $line) {
            $io->writeln('  · ' . $line);
        }
        $io->writeln(sprintf('Skipped (already set): %d', count($skipped)));
        foreach ($skipped as $line) {
            $io->writeln('  · ' . $line);
        }
        if ($misses !== []) {
            $io->warning(sprintf('No PublicBody match for %d ámbitos:', count($misses)));
            foreach ($misses as $line) {
                $io->writeln('  · ' . $line);
            }
        }

        return Command::SUCCESS;
    }

    private function findByNameInsensitive(string $name): ?\App\Entity\PublicBody
    {
        $qb = $this->publicBodyRepository->createQueryBuilder('pb')
            ->where('LOWER(pb.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
