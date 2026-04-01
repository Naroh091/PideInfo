<?php

namespace App\Command;

use App\Repository\ComplaintOrganismRepository;
use App\Repository\PublicBodyRepository;
use Cocur\Slugify\Slugify;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-slugs',
    description: 'Generate slugs for ComplaintOrganism and PublicBody entities',
)]
class GenerateSlugsCommand extends Command
{
    public function __construct(
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slugify = new Slugify();

        $io->section('Generating ComplaintOrganism slugs');
        $organisms = $this->complaintOrganismRepository->findAll();
        $organismSlugs = [];
        foreach ($organisms as $organism) {
            $base = $slugify->slugify($organism->getShortName() ?? $organism->getName());
            $slug = $this->ensureUnique($base, $organismSlugs);
            $organismSlugs[] = $slug;
            $organism->setSlug($slug);
            $io->writeln(sprintf('  %s → %s', $organism->getName(), $slug));
        }

        $io->section('Generating PublicBody slugs');
        $bodies = $this->publicBodyRepository->findAll();
        $bodySlugs = [];
        foreach ($bodies as $body) {
            $base = $slugify->slugify($body->getName());
            $slug = $this->ensureUnique($base, $bodySlugs);
            $bodySlugs[] = $slug;
            $body->setSlug($slug);
            $io->writeln(sprintf('  %s → %s', $body->getName(), $slug));
        }

        $this->entityManager->flush();

        $io->success(sprintf('Generated slugs for %d organisms and %d public bodies.', count($organisms), count($bodies)));

        return Command::SUCCESS;
    }

    private function ensureUnique(string $base, array $existing): string
    {
        $slug = $base;
        $i = 2;
        while (in_array($slug, $existing, true)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
