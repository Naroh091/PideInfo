<?php

namespace App\Command;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:documents:cleanup-bad-ctbg-notifications',
    description: 'Delete CTBG "notification" documents that are actually HTML pages saved as PDFs (a regression of the agent inbox download path).',
)]
class CleanupBadCtbgDocumentsCommand extends Command
{
    private const HTML_MARKERS = ['<!doctype html', '<html', '<!DOCTYPE HTML'];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete (default: dry-run)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max documents to inspect (0 = all)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = (int) $input->getOption('limit');

        // sourceMetadata is JSON; matching by LIKE on the serialized text
        // avoids needing JSON_EXTRACT (which Doctrine DQL doesn't expose by
        // default). The originalFilename pattern narrows the candidate set.
        $qb = $this->documentRepository->createQueryBuilder('d')
            ->where('d.originalFilename LIKE :pattern')
            ->andWhere('CAST(d.sourceMetadata AS TEXT) LIKE :srcLike')
            ->setParameter('pattern', 'Notificaci%n Electr%')
            ->setParameter('srcLike', '%"source":"consejo_ctbg"%')
            ->orderBy('d.createdAt', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var Document[] $candidates */
        $candidates = $qb->getQuery()->getResult();

        if (!$candidates) {
            $io->success('No hay documentos candidatos.');
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Cleanup CTBG inbox docs (%d candidato%s — %s)',
            count($candidates),
            count($candidates) === 1 ? '' : 's',
            $apply ? 'BORRANDO' : 'dry-run',
        ));

        $bad = [];
        foreach ($candidates as $doc) {
            $stored = $doc->getStoredFilename();
            if (!$stored || !$this->documentsStorage->fileExists($stored)) {
                continue;
            }
            try {
                $stream = $this->documentsStorage->readStream($stored);
                $head = strtolower(stream_get_contents($stream, 200) ?: '');
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('No se pudo leer %s: %s', $stored, $e->getMessage()));
                continue;
            }
            $isHtml = false;
            foreach (self::HTML_MARKERS as $marker) {
                if (str_contains($head, strtolower($marker))) {
                    $isHtml = true;
                    break;
                }
            }
            if ($isHtml) {
                $bad[] = $doc;
            }
        }

        if (!$bad) {
            $io->success('Ningún candidato resultó ser HTML.');
            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'fileSize', 'createdAt', 'AR', 'originalFilename'],
            array_map(fn (Document $d) => [
                (string) $d->getId(),
                $d->getFileSize(),
                $d->getCreatedAt()?->format('Y-m-d H:i'),
                $d->getAccessRequest()?->getId() ? (string) $d->getAccessRequest()->getId() : '—',
                $d->getOriginalFilename(),
            ], $bad),
        );

        if (!$apply) {
            $io->warning(sprintf(
                '%d documento(s) HTML detectado(s). Re-ejecuta con --apply para borrar.',
                count($bad),
            ));
            return Command::SUCCESS;
        }

        $deletedFiles = 0;
        $deletedRows = 0;
        foreach ($bad as $doc) {
            $stored = $doc->getStoredFilename();
            if ($stored && $this->documentsStorage->fileExists($stored)) {
                try {
                    $this->documentsStorage->delete($stored);
                    $deletedFiles++;
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Error borrando fichero %s: %s', $stored, $e->getMessage()));
                }
            }
            $this->entityManager->remove($doc);
            $deletedRows++;
        }
        $this->entityManager->flush();

        $io->success(sprintf(
            'Borrados %d documento(s) en BD y %d fichero(s) en storage.',
            $deletedRows,
            $deletedFiles,
        ));
        return Command::SUCCESS;
    }
}
