<?php

declare(strict_types=1);

namespace App\Service\Legal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Owns the legalize-es checkout at LEGALIZE_PATH.
 *
 * Cloning and updating are the same operation on purpose — that is what makes the command
 * idempotent and what lets the 8am cron be a single entry.
 *
 * The checkout is READ-ONLY for the application: nothing in the app ever writes inside it.
 * That is the only reason `git reset --hard` here is safe, and it must stay true.
 */
final class LegalizeRepositoryManager
{
    /**
     * Not --depth=1. legalize-es takes commits every day, and the incremental sync diffs the
     * previously synced SHA against the new one — with depth 1 that old commit object is gone
     * and every daily run degrades into a full rescan.
     */
    private const CLONE_DEPTH = 50;

    private const BRANCH = 'main';

    private const GIT_TIMEOUT = 1800;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(LEGALIZE_PATH)%')]
        private readonly string $path,
        #[Autowire('%env(LEGALIZE_REPO_URL)%')]
        private readonly string $repoUrl,
    ) {
    }

    public function isCloned(): bool
    {
        return is_dir($this->path . '/.git');
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Clone if absent, fast-forward if present. Returns what changed since the last synced
     * revision so the catalogue and the indexer only touch those files.
     */
    public function ensureUpToDate(bool $forceClone = false): LegalizeSyncResult
    {
        $filesystem = new Filesystem();

        if ($forceClone && is_dir($this->path)) {
            $filesystem->remove($this->path);
        }

        // Workers run as www-data while the volume may be owned by root: without this git
        // refuses with "detected dubious ownership" and the whole feature silently stops
        // updating. It is the single most likely production failure of this subsystem.
        $this->run(['git', 'config', '--global', '--add', 'safe.directory', $this->path], null, false);

        if (!$this->isCloned()) {
            $filesystem->mkdir($this->path);

            $this->run([
                'git', 'clone',
                '--single-branch', '--branch', self::BRANCH,
                '--depth', (string) self::CLONE_DEPTH,
                $this->repoUrl, $this->path,
            ], null);

            $newSha = $this->head();
            $this->storeHead($newSha);

            return new LegalizeSyncResult(null, $newSha, null, [], true);
        }

        $base = $this->resolveDiffBase();

        $this->run(['git', 'fetch', '--depth', (string) self::CLONE_DEPTH, 'origin', self::BRANCH], $this->path);
        $this->run(['git', 'reset', '--hard', 'FETCH_HEAD'], $this->path);

        $newSha = $this->head();

        if ($base === null) {
            $this->logger->warning('legalize: no usable diff base, falling back to a full scan');
            $this->storeHead($newSha);

            return new LegalizeSyncResult(null, $newSha, null, [], false);
        }

        if ($base === $newSha) {
            $this->storeHead($newSha);

            return new LegalizeSyncResult($base, $newSha, [], [], false);
        }

        $changed = $this->diff($base, $newSha, 'ACMRT');
        $deleted = $this->diff($base, $newSha, 'D');

        $this->storeHead($newSha);

        return new LegalizeSyncResult($base, $newSha, $changed, $deleted, false);
    }

    /**
     * The base is the SHA we last *finished* a catalogue sync at, not simply HEAD: if a sync
     * died half way through, HEAD would already be ahead of what the database knows and the
     * diff would skip the files we never ingested.
     */
    private function resolveDiffBase(): ?string
    {
        $stored = $this->connection->fetchOne('SELECT head_sha FROM legal_sync_state WHERE id = 1');

        if (is_string($stored) && $stored !== '' && $this->revisionExists($stored)) {
            return $stored;
        }

        // Shallow history may have dropped the stored commit. Local HEAD is the next best
        // guess; if even that fails we return null and the caller rescans everything.
        $head = $this->head();

        return $head !== '' ? $head : null;
    }

    private function revisionExists(string $sha): bool
    {
        $process = new Process(['git', 'cat-file', '-e', $sha . '^{commit}'], $this->path);
        $process->run();

        return $process->isSuccessful();
    }

    private function head(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'], $this->path));
    }

    /**
     * @param string $filter git --diff-filter: ACMRT for touched files, D for deleted ones
     *
     * @return list<string>
     */
    private function diff(string $from, string $to, string $filter): array
    {
        try {
            $output = $this->run(
                ['git', 'diff', '--name-only', '--diff-filter=' . $filter, $from, $to],
                $this->path,
            );
        } catch (ProcessFailedException $e) {
            $this->logger->warning('legalize: git diff failed, full scan required', ['error' => $e->getMessage()]);

            return [];
        }

        $paths = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line !== '' && str_ends_with($line, '.md')) {
                $paths[] = $line;
            }
        }

        return $paths;
    }

    private function storeHead(string $sha): void
    {
        $this->connection->executeStatement(
            'UPDATE legal_sync_state SET head_sha = :sha, synced_at = NOW() WHERE id = 1',
            ['sha' => $sha],
        );
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, ?string $cwd, bool $mustSucceed = true): string
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(self::GIT_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            if ($mustSucceed) {
                throw new ProcessFailedException($process);
            }

            $this->logger->debug('legalize: git command failed (ignored)', [
                'command' => implode(' ', $command),
                'error' => $process->getErrorOutput(),
            ]);

            return '';
        }

        return $process->getOutput();
    }
}
