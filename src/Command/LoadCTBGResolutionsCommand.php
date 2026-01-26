<?php

namespace App\Command;

use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:ctbg:load-resolutions',
    description: 'Load CTBG resolutions into Qdrant vector store (future implementation)',
)]
class LoadCTBGResolutionsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'ai.store.qdrant.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('CTBG Resolutions Loader');

        $io->warning([
            'This command is prepared for future implementation.',
            '',
            'The resolutions loader will support storing CTBG resolutions in Qdrant with the following metadata:',
            '  - reference: Resolution number (e.g., "R/0123/2023")',
            '  - date: Resolution date',
            '  - outcome: estimada/desestimada/parcial',
            '  - publicBody: Public body involved',
            '  - topic: Main topic',
            '',
            'The data source (scraping, local PDFs, or API) is still to be determined.',
        ]);

        return Command::SUCCESS;
    }
}
