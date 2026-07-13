<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Repository\LegalNormRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Keeps `legal_norm` in step with the checkout: one row per .md file, built from its YAML
 * frontmatter and nothing else.
 *
 * This is the tier that makes every norm in the BOE *findable*. Extracting its articulado is
 * a separate, much narrower job (LegalArticleIndexer).
 */
final class LegalCatalogSynchronizer
{
    /** Rows are handed to the repository in chunks; the repo batches the SQL again. */
    private const FLUSH_EVERY = 500;

    public function __construct(
        private readonly LegalizeFrontmatterReader $frontmatter,
        private readonly LegalNormRepository $norms,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(LEGALIZE_PATH)%')]
        private readonly string $legalizePath,
    ) {
    }

    /**
     * @param list<string>|null      $changedPaths repo-relative paths to refresh; null = full scan
     * @param list<string>           $deletedPaths repo-relative paths whose file is gone
     * @param callable(int): void|null $onProgress
     *
     * @return array{scanned: int, upserted: int, skipped: int, deleted: int}
     */
    public function sync(?array $changedPaths = null, array $deletedPaths = [], ?callable $onProgress = null): array
    {
        $paths = $changedPaths ?? $this->scanAll();

        $stats = ['scanned' => 0, 'upserted' => 0, 'skipped' => 0, 'deleted' => 0];
        $buffer = [];

        foreach ($paths as $relativePath) {
            ++$stats['scanned'];

            $row = $this->toRow($relativePath);
            if ($row === null) {
                ++$stats['skipped'];
                continue;
            }

            $buffer[] = $row;

            if (count($buffer) >= self::FLUSH_EVERY) {
                $this->norms->upsertMany($buffer);
                $stats['upserted'] += count($buffer);
                $buffer = [];

                if ($onProgress !== null) {
                    $onProgress($stats['scanned']);
                }
            }
        }

        if ($buffer !== []) {
            $this->norms->upsertMany($buffer);
            $stats['upserted'] += count($buffer);
        }

        if ($deletedPaths !== []) {
            $stats['deleted'] = $this->norms->deleteByRelativePaths($deletedPaths);
        }

        // Cheap and idempotent: re-project the whitelist on every run, so editing TrackedNorms
        // is enough to (un)track a norm — no separate command to forget about.
        $this->norms->markTracked(TrackedNorms::boeIds());

        return $stats;
    }

    /**
     * Every whitelisted id must exist in the corpus. Three of them are unverified guesses, and
     * a wrong id fails *silently* (the norm simply never gets indexed), so this check is a
     * deploy gate, not a nicety.
     *
     * @return array<string, list<array{boeId: string, title: string, officialNumber: string|null}>>
     *                       missing boeId => candidate norms found by number/name
     */
    public function verifyWhitelist(): array
    {
        $missing = [];

        foreach (TrackedNorms::boeIds() as $boeId) {
            if ($this->norms->findByBoeId($boeId) !== null) {
                continue;
            }

            $shortLabel = TrackedNorms::shortLabel($boeId) ?? '';
            $candidates = [];

            // The short label carries the official number ("Ley 9/2017" → "9/2017"), which is
            // the one thing about the norm we are sure of.
            if (preg_match('#(\d+[A-Z/-]*\d*/\d{4})#u', $shortLabel, $m)) {
                foreach ($this->norms->findByOfficialNumberAndRank($m[1]) as $candidate) {
                    $candidates[] = [
                        'boeId' => $candidate->getBoeId(),
                        'title' => $candidate->getTitle(),
                        'officialNumber' => $candidate->getOfficialNumber(),
                    ];
                }
            }

            if ($candidates === []) {
                foreach ($this->norms->searchByName($shortLabel, null, 5) as $candidate) {
                    $candidates[] = [
                        'boeId' => $candidate->getBoeId(),
                        'title' => $candidate->getTitle(),
                        'officialNumber' => $candidate->getOfficialNumber(),
                    ];
                }
            }

            $missing[$boeId] = $candidates;
        }

        return $missing;
    }

    /**
     * @return list<string> repo-relative paths of every .md norm
     */
    private function scanAll(): array
    {
        $finder = (new Finder())
            ->files()
            ->in($this->legalizePath)
            ->depth('== 1')          // es/BOE-A-….md — never .github/ or the README
            ->name('*.md')
            ->path('#^es(-[a-z]{2})?/#');

        $paths = [];
        foreach ($finder as $file) {
            $paths[] = $file->getRelativePathname();
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toRow(string $relativePath): ?array
    {
        $absolute = $this->legalizePath . '/' . $relativePath;

        if (!is_file($absolute)) {
            return null;
        }

        $meta = $this->frontmatter->read($absolute);
        if ($meta === null) {
            return null;
        }

        $boeId = (string) $meta['identifier'];
        $jurisdiction = strtok($relativePath, '/');

        if ($jurisdiction === false || !preg_match('/^es(-[a-z]{2})?$/', $jurisdiction)) {
            $this->logger->warning('legalize: unexpected jurisdiction directory', ['path' => $relativePath]);

            return null;
        }

        return [
            'boe_id' => $boeId,
            'jurisdiction' => $jurisdiction,
            'relative_path' => $relativePath,
            'title' => (string) $meta['title'],
            'official_number' => isset($meta['official_number']) ? (string) $meta['official_number'] : null,
            'norm_rank' => isset($meta['rank']) ? (string) $meta['rank'] : null,
            'rank_code' => isset($meta['rank_code']) ? (string) $meta['rank_code'] : null,
            'scope' => isset($meta['scope']) ? (string) $meta['scope'] : null,
            'department' => isset($meta['department']) ? mb_substr((string) $meta['department'], 0, 255) : null,
            'status' => isset($meta['status']) ? (string) $meta['status'] : null,
            'consolidation_status' => isset($meta['consolidation_status']) ? (string) $meta['consolidation_status'] : null,
            'publication_date' => LegalizeFrontmatterReader::toDate($meta['publication_date'] ?? null)?->format('Y-m-d'),
            'enactment_date' => LegalizeFrontmatterReader::toDate($meta['enactment_date'] ?? null)?->format('Y-m-d'),
            'last_updated' => LegalizeFrontmatterReader::toDate($meta['last_updated'] ?? null)?->format('Y-m-d'),
            'url_eli' => isset($meta['url_eli']) ? mb_substr((string) $meta['url_eli'], 0, 500) : null,
            'url_html_consolidada' => isset($meta['url_html_consolidada']) ? mb_substr((string) $meta['url_html_consolidada'], 0, 500) : null,
            'url_pdf' => isset($meta['url_pdf']) ? mb_substr((string) $meta['url_pdf'], 0, 500) : null,
            'subjects' => LegalizeFrontmatterReader::toStringList($meta['subjects'] ?? null),
        ];
    }
}
