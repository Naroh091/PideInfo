<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccessRequest;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes stale anonymous drafts: AccessRequests with no owner (created from
 * the public /redactar flow) whose session almost certainly expired. Claimed
 * drafts have a user and are never touched. Runs nightly from Schedule.php.
 *
 * Anonymous drafts are not expected to have Documents (chat attachments are
 * never persisted), but any row that somehow got one has its stored file
 * removed from the documents storage before the cascade deletes the entity.
 */
#[AsCommand(
    name: 'app:anonymous-drafts:purge',
    description: 'Borra los borradores anónimos (/redactar) sin reclamar más viejos que --days',
)]
final class PurgeAnonymousDraftsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Edad mínima (días desde su creación) para purgar un borrador', '7')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista lo que se borraría sin tocar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<AccessRequest> $stale */
        $stale = $this->entityManager->createQueryBuilder()
            ->select('ar')
            ->from(AccessRequest::class, 'ar')
            ->where('ar.user IS NULL')
            ->andWhere('ar.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('ar.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ($stale === []) {
            $io->success(sprintf('No hay borradores anónimos de más de %d días.', $days));

            return Command::SUCCESS;
        }

        foreach ($stale as $draft) {
            $io->writeln(sprintf(
                '%s %s · creado %s · «%s»',
                $dryRun ? '[dry-run]' : 'Borrando',
                $draft->getId()->toRfc4122(),
                $draft->getCreatedAt()->format('Y-m-d'),
                mb_substr($draft->getTitle() !== '' ? $draft->getTitle() : '(sin título)', 0, 60),
            ));

            if ($dryRun) {
                continue;
            }

            foreach ($draft->getDocuments() as $document) {
                $stored = $document->getStoredFilename();
                if ($stored !== null && $this->documentsStorage->fileExists($stored)) {
                    $this->documentsStorage->delete($stored);
                }
            }

            $this->entityManager->remove($draft);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s%d borrador(es) anónimo(s) de más de %d días.',
            $dryRun ? '[dry-run] Se borrarían ' : 'Borrados ',
            count($stale),
            $days,
        ));

        return Command::SUCCESS;
    }
}
