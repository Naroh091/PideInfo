<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\LegalNorm;
use App\Message\IndexLegalNormMessage;
use App\Repository\LegalArticleRepository;
use App\Repository\LegalNormRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Extracts the articulado of the tracked norms into `legal_article` and asks Elasticsearch to
 * catch up.
 *
 * The content hash is what makes the daily run cheap: legalize-es changes a handful of norms
 * a day, so all but a handful are skipped without ever being parsed.
 *
 * This is also the ONLY writer of `legal_article`, which is why it dispatches the index
 * message by hand. Do not add a Doctrine listener: replaceForNorm() writes through DBAL and
 * a listener would never see it.
 */
final class LegalArticleIndexer
{
    public function __construct(
        private readonly LegalNormRepository $norms,
        private readonly LegalArticleRepository $articles,
        private readonly LegalizeMarkdownParser $parser,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(LEGALIZE_PATH)%')]
        private readonly string $legalizePath,
    ) {
    }

    /**
     * @param callable(LegalNorm, string): void|null $onNorm called with (norm, outcome)
     *
     * @return array{tracked: int, indexed: int, skipped: int, missing: int, articles: int}
     */
    public function indexTracked(bool $force = false, ?string $onlyBoeId = null, ?callable $onNorm = null): array
    {
        $boeIds = $onlyBoeId !== null
            ? [$onlyBoeId]
            : array_map(static fn (LegalNorm $n): string => $n->getBoeId(), $this->norms->findTracked());

        $stats = ['tracked' => count($boeIds), 'indexed' => 0, 'skipped' => 0, 'missing' => 0, 'articles' => 0];

        foreach ($boeIds as $boeId) {
            // Re-fetched on every iteration on purpose: we clear() below, which would detach
            // every norm still sitting in a preloaded array and silently drop their updates.
            $norm = $this->norms->findByBoeId($boeId);

            if ($norm === null) {
                ++$stats['missing'];
                $this->logger->warning('legalize: whitelisted norm not in the catalogue', ['boeId' => $boeId]);

                continue;
            }

            $outcome = $this->indexNorm($norm, $force, $stats);

            if ($onNorm !== null) {
                $onNorm($norm, $outcome);
            }

            // One norm at a time: some of these files are 1.7 MB and the parser expands them
            // into large arrays.
            $this->entityManager->clear();
            gc_collect_cycles();
        }

        return $stats;
    }

    /**
     * @param array{tracked: int, indexed: int, skipped: int, missing: int, articles: int} $stats
     *
     * @return string 'indexed' | 'skipped' | 'missing' | 'no_articles'
     */
    private function indexNorm(LegalNorm $norm, bool $force, array &$stats): string
    {
        $absolute = $this->legalizePath . '/' . $norm->getRelativePath();

        if (!is_file($absolute)) {
            ++$stats['missing'];
            $this->logger->warning('legalize: tracked norm file missing', [
                'boeId' => $norm->getBoeId(),
                'path' => $norm->getRelativePath(),
            ]);

            return 'missing';
        }

        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            ++$stats['missing'];

            return 'missing';
        }

        if (!$force && $hash === $norm->getContentHash()) {
            ++$stats['skipped'];

            return 'skipped';
        }

        $markdown = file_get_contents($absolute);
        if ($markdown === false) {
            ++$stats['missing'];

            return 'missing';
        }

        $parsed = $this->parser->parse($markdown);
        $written = $this->articles->replaceForNorm($norm, $parsed);

        $norm->setContentHash($hash)
            ->setArticleCount($written)
            ->setParseStatus($written > 0 ? LegalNorm::PARSE_OK : LegalNorm::PARSE_NO_ARTICLES)
            ->setArticlesIndexedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $stats['articles'] += $written;
        ++$stats['indexed'];

        $this->bus->dispatch(new IndexLegalNormMessage($norm->getBoeId()));

        return $written > 0 ? 'indexed' : 'no_articles';
    }
}
