<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DynamicClientMetadataRepository;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds the `refresh_token` grant (and `offline_access` scope) to dynamic OAuth2
 * clients that registered before the DCR default included it. Idempotent — a
 * client already carrying both is left untouched.
 */
#[AsCommand(
    name: 'app:oauth:backfill-refresh-grant',
    description: 'Grant refresh_token + offline_access to dynamic OAuth2 clients missing them.',
)]
final class BackfillOauthRefreshGrantCommand extends Command
{
    public function __construct(
        private readonly DynamicClientMetadataRepository $metadataRepository,
        private readonly ClientManagerInterface $clientManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without persisting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $metadatas = $this->metadataRepository->findBy(['dynamic' => true]);
        if ([] === $metadatas) {
            $io->success('No dynamic clients found.');

            return Command::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($metadatas as $metadata) {
            $clientId = $metadata->getClientIdentifier();
            $client = $this->clientManager->find($clientId);
            if (null === $client) {
                ++$missing;
                $io->warning(\sprintf('Client %s in metadata table but not in oauth2_client.', $clientId));
                continue;
            }

            $grants = $client->getGrants();
            $grantNames = array_map(static fn (Grant $g) => $g->__toString(), $grants);
            $scopes = $client->getScopes();
            $scopeNames = array_map(static fn (Scope $s) => $s->__toString(), $scopes);

            $needsGrant = !in_array('refresh_token', $grantNames, true);
            $needsScope = !in_array('offline_access', $scopeNames, true);

            if (!$needsGrant && !$needsScope) {
                ++$skipped;
                continue;
            }

            if ($needsGrant) {
                $grants[] = new Grant('refresh_token');
            }
            if ($needsScope) {
                $scopes[] = new Scope('offline_access');
            }

            $client->setGrants(...$grants);
            $client->setScopes(...$scopes);

            $io->writeln(\sprintf(
                '%s %s — grants: [%s] scopes: [%s]',
                $dryRun ? '[dry-run]' : '[updated]',
                $clientId,
                implode(', ', array_map(static fn (Grant $g) => $g->__toString(), $grants)),
                implode(', ', array_map(static fn (Scope $s) => $s->__toString(), $scopes)),
            ));

            if (!$dryRun) {
                $this->clientManager->save($client);
            }
            ++$updated;
        }

        $io->success(\sprintf(
            '%s %d, skipped %d already-correct, %d orphaned metadata.',
            $dryRun ? 'Would update' : 'Updated',
            $updated,
            $skipped,
            $missing,
        ));

        return Command::SUCCESS;
    }
}
