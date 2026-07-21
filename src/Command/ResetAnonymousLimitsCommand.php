<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Dev/test utility: clears every anti-abuse counter for the anonymous /redactar
 * flow in one shot, so a manual test loop doesn't have to reset three layers by
 * hand. NOT wired into any schedule — it is meant to be run interactively.
 *
 * Resets:
 *  - per-IP limiters (chat turns, draft create, moderation strikes) → the whole
 *    `cache.rate_limiter` pool, which is dedicated to limiters;
 *  - the global generation circuit breaker → its single `'global'` key (so the
 *    rest of the shared `cache.app` Redis pool is left intact);
 *  - the per-draft freeze / turn cap living in `metadata['anonymous']` of every
 *    ownerless AccessRequest (moderation incidents cleared, turns reset to 0).
 */
#[AsCommand(
    name: 'app:anonymous-drafts:reset-limits',
    description: 'Resetea todos los límites anti-abuso del flujo anónimo (/redactar): limiters por IP, breaker global y freeze/turnos por borrador',
)]
final class ResetAnonymousLimitsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $rateLimiterPool,
        #[Autowire(service: 'limiter.anonymous_generation_global')]
        private readonly RateLimiterFactory $globalGenerationLimiter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra lo que se resetearía sin tocar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Count the ownerless drafts whose per-draft counters would be reset.
        // jsonb_exists() (not the `?` operator) so DBAL doesn't read it as a
        // positional parameter placeholder.
        $draftCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM access_request WHERE user_id IS NULL AND metadata IS NOT NULL AND jsonb_exists(metadata::jsonb, 'anonymous')",
        );

        if ($dryRun) {
            $io->writeln('<info>[dry-run]</info> se limpiaría el pool <comment>cache.rate_limiter</comment> (chat/create/strikes por IP)');
            $io->writeln('<info>[dry-run]</info> se resetearía la clave <comment>global</comment> del breaker anonymous_generation_global');
            $io->writeln(sprintf('<info>[dry-run]</info> se resetearían turns/moderation en <comment>%d</comment> borrador(es) anónimo(s)', $draftCount));

            return Command::SUCCESS;
        }

        $this->rateLimiterPool->clear();
        $io->writeln('<info>ok</info>   limiters por IP reiniciados (cache.rate_limiter)');

        $this->globalGenerationLimiter->create('global')->reset();
        $io->writeln('<info>ok</info>   breaker global reiniciado (clave «global»)');

        $affected = (int) $this->connection->executeStatement(
            "UPDATE access_request
             SET metadata = jsonb_set(jsonb_set(metadata::jsonb, '{anonymous,turns}', '0'), '{anonymous,moderation}', '[]')::json
             WHERE user_id IS NULL AND metadata IS NOT NULL AND jsonb_exists(metadata::jsonb, 'anonymous')",
        );
        $io->writeln(sprintf('<info>ok</info>   freeze/turnos reseteados en %d borrador(es) anónimo(s)', $affected));

        $io->success('Límites del flujo anónimo reiniciados.');

        return Command::SUCCESS;
    }
}
