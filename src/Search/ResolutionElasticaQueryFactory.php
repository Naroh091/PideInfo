<?php

namespace App\Search;

use Elastica\Aggregation\Avg;
use Elastica\Aggregation\Cardinality;
use Elastica\Aggregation\Terms;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchAll;
use Elastica\Query\MultiMatch;
use Elastica\Query\Range;
use Elastica\Query\Term;

/**
 * Translates a ResolutionSearchQuery into an Elastica query.
 *
 * Pure: no connection, no container. Everything about the Elasticsearch contract
 * that is worth asserting lives here, so it can be unit tested with ->toArray().
 */
final class ResolutionElasticaQueryFactory
{
    public const AGG_OUTCOMES = 'outcomes';
    public const AGG_AVG_DAYS = 'avg_days';
    public const AGG_DISTINCT_PUBLIC_BODIES = 'distinct_public_bodies';

    /**
     * Free-text fields and their weights. `fullText` is deliberately weak: it is the raw
     * PDF, so a hit there is far less meaningful than one in the reference or the subject.
     */
    private const SEARCH_FIELDS = [
        'referenceNumber^5',
        'subject^3',
        'summary^2',
        'keywords.folded^2',
        'publicBodyName',
        'claimReason',
        'fullText^0.5',
    ];

    public function createSearchQuery(ResolutionSearchQuery $query): Query
    {
        // ResolutionSearchQuery::fromArray caps the page inside the result window; the
        // min() only guards direct constructor calls.
        $windowCeiling = max(0, ResolutionSearchQuery::MAX_RESULT_WINDOW - $query->perPage);

        $esQuery = Query::create($this->createBoolQuery($query))
            ->setTrackTotalHits(true)
            ->setSource(false)
            ->setFrom(min($query->offset(), $windowCeiling))
            ->setSize($query->perPage)
            ->setSort($this->createSort($query));

        $outcomes = (new Terms(self::AGG_OUTCOMES))->setField('outcome')->setSize(30);
        $esQuery->addAggregation($outcomes);

        return $esQuery;
    }

    /**
     * Aggregation-only query: no hits, just the numbers behind the summary cards.
     */
    public function createAggregatesQuery(ResolutionSearchQuery $query): Query
    {
        $esQuery = Query::create($this->createBoolQuery($query))
            ->setTrackTotalHits(true)
            ->setSource(false)
            ->setSize(0);

        $esQuery->addAggregation((new Terms(self::AGG_OUTCOMES))->setField('outcome')->setSize(30));
        $esQuery->addAggregation((new Avg(self::AGG_AVG_DAYS))->setField('daysToResolve'));
        $esQuery->addAggregation(
            (new Cardinality(self::AGG_DISTINCT_PUBLIC_BODIES))
                ->setField('publicBodyName.keyword')
                // Above the real number of distinct bodies, so the count is exact in practice.
                ->setPrecisionThreshold(40000)
        );

        return $esQuery;
    }

    /**
     * Terms aggregation used by the autocomplete endpoints.
     */
    public function createSuggestQuery(string $field, string $term, int $limit): Query
    {
        $agg = (new Terms('suggestions'))->setField($field)->setSize($limit);

        if ($term !== '') {
            // `include` matches the whole term, so it must be wrapped to behave like a LIKE %term%.
            $agg->setInclude('.*'.$this->caseInsensitivePattern($term).'.*');
        }

        return Query::create(new MatchAll())
            ->setSize(0)
            ->setSource(false)
            ->addAggregation($agg);
    }

    private function createBoolQuery(ResolutionSearchQuery $query): BoolQuery
    {
        $bool = new BoolQuery();

        if ($query->search !== '') {
            $bool->addMust(
                (new MultiMatch())
                    ->setQuery($query->search)
                    ->setFields(self::SEARCH_FIELDS)
                    ->setType(MultiMatch::TYPE_BEST_FIELDS)
                    ->setOperator(MultiMatch::OPERATOR_AND)
            );
        } else {
            $bool->addMust(new MatchAll());
        }

        if ($query->organism !== '') {
            $bool->addFilter(new Term(['complaintOrganism.id' => $query->organism]));
        }

        if ($query->outcome !== '') {
            $bool->addFilter(new Term(['outcome' => $query->outcome]));
        }

        if ($query->keyword !== '') {
            $bool->addFilter(new Term(['keywords.folded' => $query->keyword]));
        }

        if ($query->limit !== '') {
            $bool->addFilter(new Term(['limits' => $query->limit]));
        }

        if ($query->inadmissionCause !== '') {
            $bool->addFilter(new Term(['inadmissionCauses' => $query->inadmissionCause]));
        }

        if ($query->publicBody !== '') {
            if ($query->publicBodyExact) {
                $bool->addFilter(new Term(['publicBodyName.folded' => $query->publicBody]));
            } else {
                $bool->addFilter(
                    (new MultiMatch())
                        ->setQuery($query->publicBody)
                        ->setFields(['publicBodyName'])
                        ->setOperator(MultiMatch::OPERATOR_AND)
                );
            }
        }

        $dateRange = array_filter([
            'gte' => $query->dateFrom !== '' ? $query->dateFrom : null,
            'lte' => $query->dateTo !== '' ? $query->dateTo : null,
        ]);
        if ($dateRange !== []) {
            $bool->addFilter(new Range('resolutionDate', $dateRange));
        }

        $resolveTime = $query->resolveTimeRange();
        if ($resolveTime !== null) {
            [$min, $max] = $resolveTime;
            $bool->addFilter(new Range('daysToResolve', array_filter([
                'gte' => $min,
                'lte' => $max,
            ], static fn (?int $v): bool => $v !== null)));
        }

        return $bool;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function createSort(ResolutionSearchQuery $query): array
    {
        // Mirrors the Postgres ordering: undated resolutions last, newest first, id as tie-breaker.
        $byDate = [
            ['resolutionDate' => ['order' => 'desc', 'missing' => '_last']],
            ['id' => ['order' => 'desc']],
        ];

        if ($query->sortsByRelevance()) {
            return array_merge([['_score' => ['order' => 'desc']]], $byDate);
        }

        return $byDate;
    }

    /**
     * Lucene regexes are case sensitive and offer no flag to change that, so the only way
     * to match "Ministerio del Interior" while the user types "ministerio" is to expand
     * every cased letter into a character class.
     */
    private function caseInsensitivePattern(string $term): string
    {
        $pattern = '';
        foreach (mb_str_split($term) as $char) {
            $lower = mb_strtolower($char);
            $upper = mb_strtoupper($char);

            $pattern .= $lower === $upper
                ? $this->quoteRegex($char)
                : '['.$this->quoteRegex($lower).$this->quoteRegex($upper).']';
        }

        return $pattern;
    }

    /**
     * Escapes the Lucene regex metacharacters accepted by the `include` clause.
     */
    private function quoteRegex(string $term): string
    {
        return preg_replace('/([.?+*|{}\[\]()"\\\\#@&<>~^$])/u', '\\\\$1', $term) ?? $term;
    }
}
