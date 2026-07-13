<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\IndexLegalNormMessage;
use App\Repository\LegalArticleRepository;
use Elastica\Index;
use Elastica\Query;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reindexes the whole articulado of one norm.
 *
 * Wipe-and-reinsert rather than a per-article upsert, because a reform does not just change
 * article texts: it adds and REPEALS articles. An upsert would leave the deleted ones behind
 * in Elasticsearch, and the agent would go on quoting a precept that no longer exists.
 *
 * Postgres is the sole authority, exactly like IndexResolutionHandler: whatever legal_article
 * holds for this norm is what ends up in the index, which makes replays idempotent.
 */
#[AsMessageHandler]
final readonly class IndexLegalNormHandler
{
    private const BULK_SIZE = 500;

    public function __construct(
        #[Autowire(service: 'fos_elastica.object_persister.laws')]
        private ObjectPersisterInterface $objectPersister,
        #[Autowire(service: 'fos_elastica.index.laws')]
        private Index $index,
        private LegalArticleRepository $articles,
    ) {
    }

    public function __invoke(IndexLegalNormMessage $message): void
    {
        $this->index->deleteByQuery(new Query(new Query\Term(['boeId' => $message->boeId])));

        $articles = $this->articles->findByNorm($message->boeId);

        if ($articles === []) {
            $this->index->refresh();

            return;
        }

        foreach (array_chunk($articles, self::BULK_SIZE) as $chunk) {
            $this->objectPersister->insertMany($chunk);
        }

        $this->index->refresh();
    }
}
