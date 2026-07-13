<?php

declare(strict_types=1);

namespace App\Search;

use App\Repository\LegalArticleRepository;
use Elastica\Index;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * BM25 over the articulado of the tracked norms.
 *
 * Elasticsearch returns ids and highlights; Postgres returns the article. Same two-step as
 * ElasticResolutionSearch — the index is a finding aid, never the source of the text we put
 * in front of the model.
 */
final readonly class ElasticLegislationSearch implements LegislationSearchInterface
{
    public function __construct(
        #[Autowire(service: 'fos_elastica.index.laws')]
        private Index $index,
        private LegislationElasticaQueryFactory $queryFactory,
        private LegalArticleRepository $articles,
    ) {
    }

    public function search(LegislationSearchQuery $query): LegislationSearchResult
    {
        if (trim($query->query) === '') {
            return new LegislationSearchResult([], 0);
        }

        $results = $this->index->search($this->queryFactory->create($query));

        $ids = [];
        $highlights = [];

        foreach ($results->getResults() as $result) {
            $id = $result->getId();
            $ids[] = $id;

            $fragments = $result->getHighlights()['content'] ?? [];
            if ($fragments !== []) {
                $highlights[$id] = array_values($fragments);
            }
        }

        $byId = $this->articles->findByIds($ids);

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return new LegislationSearchResult(
            articles: $ordered,
            total: $results->getTotalHits(),
            highlights: $highlights,
        );
    }
}
