<?php

namespace App\Command;

use App\Repository\ComplaintOrganismRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:organisms:link',
    description: 'Link ComplaintOrganism to AutonomousCommunity using ApplicableLaw relationships',
)]
class LinkOrganismsCommand extends Command
{
    public function __construct(
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Link ComplaintOrganism → AutonomousCommunity');

        $organisms = $this->complaintOrganismRepository->findAll();
        $linked = 0;
        $skipped = 0;

        foreach ($organisms as $organism) {
            if ($organism->getAutonomousCommunity() !== null) {
                $io->text(sprintf('  %s — already linked to %s',
                    $organism->getShortName() ?? $organism->getName(),
                    $organism->getAutonomousCommunity()->getName()
                ));
                $skipped++;
                continue;
            }

            // Find the AutonomousCommunity through ApplicableLaw
            $community = null;
            foreach ($organism->getApplicableLaws() as $law) {
                if ($law->getAutonomousCommunity() !== null) {
                    $community = $law->getAutonomousCommunity();
                    break;
                }
            }

            if ($community) {
                $organism->setAutonomousCommunity($community);
                $io->text(sprintf('  %s → %s',
                    $organism->getShortName() ?? $organism->getName(),
                    $community->getName()
                ));
                $linked++;
            } else {
                $io->text(sprintf('  %s — no community found (national body)',
                    $organism->getShortName() ?? $organism->getName()
                ));
                $skipped++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Done. %d linked, %d skipped.', $linked, $skipped));

        return Command::SUCCESS;
    }
}
