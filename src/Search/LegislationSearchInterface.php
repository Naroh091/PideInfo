<?php

declare(strict_types=1);

namespace App\Search;

interface LegislationSearchInterface
{
    public function search(LegislationSearchQuery $query): LegislationSearchResult;
}
