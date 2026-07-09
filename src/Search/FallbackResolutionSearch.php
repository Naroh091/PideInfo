<?php

namespace App\Search;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Elastica\Exception\ExceptionInterface as ElasticaException;
use Psr\Log\LoggerInterface;

/**
 * Front door of the resolution search.
 *
 * Talks to Elasticsearch and, if the cluster is unreachable or the index has not been
 * populated yet, silently answers from Postgres instead. The listing must never 500
 * because of the search cluster.
 *
 * Set RESOLUTION_SEARCH_BACKEND=doctrine to bypass Elasticsearch entirely.
 */
final readonly class FallbackResolutionSearch implements ResolutionSearchInterface
{
    public const BACKEND_ELASTIC = 'elastic';
    public const BACKEND_DOCTRINE = 'doctrine';

    public function __construct(
        private ResolutionSearchInterface $elastic,
        private ResolutionSearchInterface $doctrine,
        private LoggerInterface $logger,
        private string $backend = self::BACKEND_ELASTIC,
    ) {
    }

    public function search(ResolutionSearchQuery $query): ResolutionSearchResult
    {
        if (!$this->usesElastic()) {
            return $this->doctrine->search($query);
        }

        try {
            return $this->elastic->search($query);
        } catch (ElasticaException|ElasticsearchException|TransportException $e) {
            $this->logFailure('search', $e);

            $result = $this->doctrine->search($query);

            return new ResolutionSearchResult(
                resolutions: $result->resolutions,
                total: $result->total,
                outcomeStats: $result->outcomeStats,
                degraded: true,
            );
        }
    }

    public function outcomeStats(ResolutionSearchQuery $query): array
    {
        return $this->call('outcomeStats', fn (ResolutionSearchInterface $backend): array => $backend->outcomeStats($query));
    }

    public function aggregates(ResolutionSearchQuery $query): array
    {
        return $this->call('aggregates', fn (ResolutionSearchInterface $backend): array => $backend->aggregates($query));
    }

    public function suggestKeywords(string $query = '', int $limit = 20): array
    {
        return $this->call('suggestKeywords', fn (ResolutionSearchInterface $backend): array => $backend->suggestKeywords($query, $limit));
    }

    public function suggestPublicBodies(string $query = '', int $limit = 20): array
    {
        return $this->call('suggestPublicBodies', fn (ResolutionSearchInterface $backend): array => $backend->suggestPublicBodies($query, $limit));
    }

    /**
     * @template T
     *
     * @param callable(ResolutionSearchInterface): T $operation
     *
     * @return T
     */
    private function call(string $name, callable $operation): mixed
    {
        if (!$this->usesElastic()) {
            return $operation($this->doctrine);
        }

        try {
            return $operation($this->elastic);
        } catch (ElasticaException|ElasticsearchException|TransportException $e) {
            $this->logFailure($name, $e);

            return $operation($this->doctrine);
        }
    }

    private function usesElastic(): bool
    {
        return $this->backend !== self::BACKEND_DOCTRINE;
    }

    private function logFailure(string $operation, \Throwable $e): void
    {
        $this->logger->warning('Elasticsearch resolution search failed, falling back to Postgres', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }
}
