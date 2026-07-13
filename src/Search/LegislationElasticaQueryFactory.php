<?php

declare(strict_types=1);

namespace App\Search;

use Elastica\Query;

/**
 * Builds the Elasticsearch query for the legislation index. Pure — no connection, no state —
 * so the query shape can be asserted in a unit test.
 */
final class LegislationElasticaQueryFactory
{
    /** Fragment size of a highlight: about a paragraph of a precept. */
    private const FRAGMENT_SIZE = 240;

    private const FRAGMENTS = 3;

    public function create(LegislationSearchQuery $query): Query
    {
        $bool = new Query\BoolQuery();

        // The rúbrica is a near-perfect summary of what an article regulates, so it is boosted
        // — but only to 2. Norms from the 1980s (LBRL, ROF) print their articles with NO
        // rúbrica at all, and a heavier boost systematically buried exactly the precepts that
        // matter most for a concejal.
        //
        // tie_breaker exists for the same reason: with plain best_fields, any article whose
        // *heading* matched a little always beat one whose *body* matched a lot.
        $bool->addMust(
            (new Query\MultiMatch())
                ->setQuery($query->query)
                ->setFields(['heading^2', 'content', 'breadcrumb^1.5', 'normTitle^0.5'])
                ->setType(Query\MultiMatch::TYPE_BEST_FIELDS)
                ->setTieBreaker(0.4),
        );

        if ($query->boeIds !== []) {
            $bool->addFilter(new Query\Terms('boeId', $query->boeIds));
        }

        if ($query->kinds !== []) {
            $bool->addFilter(new Query\Terms('kind', $query->kinds));
        }

        if (!$query->includeRepealed) {
            $bool->addMustNot(new Query\Term(['repealed' => true]));
        }

        $elasticaQuery = new Query($bool);
        $elasticaQuery->setSize($query->limit);
        $elasticaQuery->setSource(false);   // we rehydrate from Postgres; only ids are needed

        // Without highlights, a 9.000-character article would come back as its first 1.800
        // characters — which are almost never the part that matched.
        $elasticaQuery->setHighlight([
            'fields' => [
                'content' => [
                    'fragment_size' => self::FRAGMENT_SIZE,
                    'number_of_fragments' => self::FRAGMENTS,
                ],
            ],
            'pre_tags' => [''],
            'post_tags' => [''],
        ]);

        return $elasticaQuery;
    }
}
