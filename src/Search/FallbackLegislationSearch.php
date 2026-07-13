<?php

declare(strict_types=1);

namespace App\Search;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Elastica\Exception\ExceptionInterface as ElasticaException;
use Psr\Log\LoggerInterface;

/**
 * Front door of the legislation search: Elasticsearch, falling back to Postgres when the
 * cluster is unreachable or the index has not been populated yet.
 *
 * Set LEGISLATION_SEARCH_BACKEND=doctrine to bypass Elasticsearch entirely.
 */
final readonly class FallbackLegislationSearch implements LegislationSearchInterface
{
    public const BACKEND_ELASTIC = 'elastic';
    public const BACKEND_DOCTRINE = 'doctrine';

    public function __construct(
        private LegislationSearchInterface $elastic,
        private LegislationSearchInterface $doctrine,
        private LoggerInterface $logger,
        private string $backend = self::BACKEND_ELASTIC,
    ) {
    }

    public function search(LegislationSearchQuery $query): LegislationSearchResult
    {
        if ($this->backend === self::BACKEND_DOCTRINE) {
            return $this->doctrine->search($query);
        }

        try {
            return $this->elastic->search($query);
        } catch (ElasticaException|ElasticsearchException|TransportException $e) {
            $this->logger->warning('Elasticsearch legislation search failed, falling back to Postgres', [
                'exception' => $e,
            ]);

            $result = $this->doctrine->search($query);

            return new LegislationSearchResult(
                articles: $result->articles,
                total: $result->total,
                highlights: $result->highlights,
                degraded: true,
            );
        }
    }
}
