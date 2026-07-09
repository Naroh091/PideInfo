<?php

namespace App\Command;

use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:migrate-to-public-body',
    description: 'Create missing PublicBody rows from resolution data, generate slugs, and link resolutions via FK',
)]
class MigrateToPublicBodyCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $slugify = new Slugify();

        // ── Step 1: Insert missing public bodies ──
        $io->section('Step 1: Creating missing public bodies');

        $missing = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT r.public_body_name
             FROM resolution r
             LEFT JOIN public_body pb ON LOWER(pb.name) = LOWER(r.public_body_name)
             WHERE pb.id IS NULL
               AND r.public_body_name IS NOT NULL
               AND TRIM(r.public_body_name) <> ''
             ORDER BY r.public_body_name"
        );

        $io->writeln(sprintf('Found <info>%d</info> public body names without a public_body row.', count($missing)));

        if (!$dryRun && count($missing) > 0) {
            $inserted = 0;
            foreach ($missing as $row) {
                $name = $row['public_body_name'];
                $level = $this->guessLevel($name);
                $this->connection->insert('public_body', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'name' => $name,
                    'level' => $level,
                ]);
                $inserted++;
            }
            $io->writeln(sprintf('Inserted <info>%d</info> new public bodies.', $inserted));
        }

        // ── Step 2: Generate slugs for all public bodies without one ──
        $io->section('Step 2: Generating slugs');

        $existingSlugs = $this->connection->fetchFirstColumn(
            "SELECT slug FROM public_body WHERE slug IS NOT NULL"
        );
        $slugIndex = array_flip($existingSlugs);

        $withoutSlug = $this->connection->fetchAllAssociative(
            "SELECT id, name FROM public_body WHERE slug IS NULL ORDER BY name"
        );

        $io->writeln(sprintf('Found <info>%d</info> public bodies without slug.', count($withoutSlug)));

        if (!$dryRun) {
            $slugged = 0;
            foreach ($withoutSlug as $row) {
                $base = $slugify->slugify($row['name']);
                if ($base === '') {
                    $base = 'entidad';
                }
                $slug = $base;
                $i = 2;
                while (isset($slugIndex[$slug])) {
                    $slug = $base . '-' . $i;
                    $i++;
                }
                $slugIndex[$slug] = true;

                $this->connection->update('public_body', ['slug' => $slug], ['id' => $row['id']]);
                $slugged++;
            }
            $io->writeln(sprintf('Generated <info>%d</info> slugs.', $slugged));
        }

        // ── Step 3: Link resolutions to public_body via FK ──
        $io->section('Step 3: Linking resolutions to public bodies');

        $unlinked = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM resolution r
             WHERE r.public_body_id IS NULL
               AND r.public_body_name IS NOT NULL
               AND TRIM(r.public_body_name) <> ''"
        );

        $io->writeln(sprintf('Found <info>%d</info> resolutions to link.', $unlinked));

        if (!$dryRun && $unlinked > 0) {
            $affected = $this->connection->executeStatement(
                "UPDATE resolution r
                 SET public_body_id = pb.id
                 FROM public_body pb
                 WHERE LOWER(r.public_body_name) = LOWER(pb.name)
                   AND r.public_body_id IS NULL"
            );
            $io->writeln(sprintf('Linked <info>%d</info> resolutions.', $affected));
        }

        // ── Step 4: Deduplicate case-insensitive public body names ──
        $io->section('Step 4: Deduplicating public bodies (case-insensitive)');

        $dupeGroups = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT LOWER(TRIM(name)) FROM public_body GROUP BY LOWER(TRIM(name)) HAVING COUNT(*) > 1
            ) sub"
        );

        $io->writeln(sprintf('Found <info>%d</info> duplicate groups.', $dupeGroups));

        if (!$dryRun && $dupeGroups > 0) {
            $rankingCte = <<<'SQL'
                WITH ranked AS (
                    SELECT
                        id,
                        name,
                        LOWER(TRIM(name)) AS normalized,
                        ROW_NUMBER() OVER (
                            PARTITION BY LOWER(TRIM(name))
                            ORDER BY
                                (name = UPPER(name))::int ASC,
                                LENGTH(name) DESC,
                                name
                        ) AS rn
                    FROM public_body
                    WHERE LOWER(TRIM(name)) IN (
                        SELECT LOWER(TRIM(name)) FROM public_body
                        GROUP BY LOWER(TRIM(name)) HAVING COUNT(*) > 1
                    )
                )
            SQL;

            // 4a) Reassign resolution FK from duplicates to canonical
            $reassigned = $this->connection->executeStatement(
                $rankingCte . ",
                canonical AS (
                    SELECT normalized, id AS canonical_id FROM ranked WHERE rn = 1
                ),
                dupes AS (
                    SELECT r.id AS dupe_id, c.canonical_id
                    FROM ranked r
                    JOIN canonical c ON c.normalized = r.normalized
                    WHERE r.rn > 1
                )
                UPDATE resolution
                SET public_body_id = d.canonical_id
                FROM dupes d
                WHERE resolution.public_body_id = d.dupe_id"
            );
            $io->writeln(sprintf('  Reassigned <info>%d</info> resolution FKs.', $reassigned));

            // 4a-ii) Reassign access_request.public_body_id from duplicates to canonical
            $reassignedAr = $this->connection->executeStatement(
                $rankingCte . ",
                canonical AS (
                    SELECT normalized, id AS canonical_id FROM ranked WHERE rn = 1
                ),
                dupes AS (
                    SELECT r.id AS dupe_id, c.canonical_id
                    FROM ranked r
                    JOIN canonical c ON c.normalized = r.normalized
                    WHERE r.rn > 1
                )
                UPDATE access_request
                SET public_body_id = d.canonical_id
                FROM dupes d
                WHERE access_request.public_body_id = d.dupe_id"
            );
            $io->writeln(sprintf('  Reassigned <info>%d</info> access_request FKs.', $reassignedAr));

            // 4a-iii) Reassign access_request.original_public_body_id from duplicates to canonical
            $reassignedArOrig = $this->connection->executeStatement(
                $rankingCte . ",
                canonical AS (
                    SELECT normalized, id AS canonical_id FROM ranked WHERE rn = 1
                ),
                dupes AS (
                    SELECT r.id AS dupe_id, c.canonical_id
                    FROM ranked r
                    JOIN canonical c ON c.normalized = r.normalized
                    WHERE r.rn > 1
                )
                UPDATE access_request
                SET original_public_body_id = d.canonical_id
                FROM dupes d
                WHERE access_request.original_public_body_id = d.dupe_id"
            );
            $io->writeln(sprintf('  Reassigned <info>%d</info> access_request original FKs.', $reassignedArOrig));

            // 4b) Normalize resolution.public_body_name to canonical spelling
            $normalized = $this->connection->executeStatement(
                $rankingCte . ",
                canonical AS (
                    SELECT normalized, name AS canonical_name FROM ranked WHERE rn = 1
                )
                UPDATE resolution
                SET public_body_name = c.canonical_name
                FROM canonical c
                WHERE LOWER(TRIM(resolution.public_body_name)) = c.normalized
                  AND resolution.public_body_name <> c.canonical_name"
            );
            $io->writeln(sprintf('  Normalized <info>%d</info> resolution names.', $normalized));

            // 4c) Delete duplicate public_body rows
            $deleted = $this->connection->executeStatement(
                $rankingCte . "
                DELETE FROM public_body WHERE id IN (SELECT id FROM ranked WHERE rn > 1)"
            );
            $io->writeln(sprintf('  Deleted <info>%d</info> duplicate public bodies.', $deleted));

            // 4d) Regenerate slugs for canonical rows that inherited a duplicate's slug
            $withoutSlug2 = $this->connection->fetchAllAssociative(
                "SELECT id, name FROM public_body WHERE slug IS NULL ORDER BY name"
            );
            if (count($withoutSlug2) > 0) {
                $existingSlugs2 = $this->connection->fetchFirstColumn(
                    "SELECT slug FROM public_body WHERE slug IS NOT NULL"
                );
                $slugIndex2 = array_flip($existingSlugs2);
                foreach ($withoutSlug2 as $row) {
                    $base = $slugify->slugify($row['name']);
                    if ($base === '') {
                        $base = 'entidad';
                    }
                    $slug = $base;
                    $i = 2;
                    while (isset($slugIndex2[$slug])) {
                        $slug = $base . '-' . $i;
                        $i++;
                    }
                    $slugIndex2[$slug] = true;
                    $this->connection->update('public_body', ['slug' => $slug], ['id' => $row['id']]);
                }
                $io->writeln(sprintf('  Regenerated <info>%d</info> slugs.', count($withoutSlug2)));
            }
        }

        // ── Summary ──
        if ($dryRun) {
            $io->warning('Dry run — no changes were made. Remove --dry-run to execute.');
        } else {
            $stillUnlinked = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM resolution
                 WHERE public_body_id IS NULL
                   AND public_body_name IS NOT NULL
                   AND TRIM(public_body_name) <> ''"
            );
            $io->success(sprintf(
                'Done. %d resolutions still unlinked (name mismatch).',
                $stillUnlinked
            ));
            // The bulk UPDATEs above rewrite resolution.public_body_name behind Doctrine's
            // back, so the Elasticsearch index no longer matches those rows.
            $io->note('Run `bin/console fos:elastica:populate --index=resolutions` to re-sync the search index.');
        }

        return Command::SUCCESS;
    }

    private function guessLevel(string $name): string
    {
        $lower = mb_strtolower($name);

        $localPrefixes = ['ayuntamiento ', 'diputación ', 'diputacion ', 'cabildo ', 'consell insular', 'mancomunidad '];
        foreach ($localPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'local';
            }
        }

        $autonomousPrefixes = ['consejería ', 'consejeria ', 'conselleria ', 'junta de ', 'gobierno de ', 'parlamento de '];
        foreach ($autonomousPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'autonomous';
            }
        }

        $statePrefixes = ['ministerio ', 'congreso ', 'senado'];
        foreach ($statePrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'state';
            }
        }

        return 'other';
    }
}
