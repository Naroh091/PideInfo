<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\AccessRequest;
use App\Entity\ComplaintOrganism;
use App\Repository\ComplaintOrganismRepository;

/**
 * Resolves which ComplaintOrganisms' doctrine should be PRIORITISED when drafting
 * a request/complaint/alegation for a given AccessRequest.
 *
 * The priority set is the guarantee body competent for the reclaimed
 * administration — derived from `AccessRequest → ApplicableLaw → ComplaintOrganism`
 * — plus the CTBG, which stays in the priority set for every case because it is
 * the reference body the regional councils look to. Both are weighted equally;
 * relevance decides the order within the set (see {@see DoctrinePriorityBoostTrait}).
 *
 * The result is a list of organism UUIDs (RFC 4122) compared against the
 * AUTHORITATIVE `Resolution::getComplaintOrganism()` / `Criterion::getComplaintOrganism()`
 * relationship after the retriever rehydrates entities — never against the vector
 * metadata `source`, whose codes do not map 1:1 to organism short names
 * (CTAR≠CTA, CRT≠CTCLM, CTPD≠CTPDA).
 */
final class DoctrinePriorityResolver
{
    /** @var list<string>|null Memoized CTBG organism id(s); null until first lookup. */
    private ?array $ctbgIdsCache = null;

    public function __construct(
        private readonly ComplaintOrganismRepository $organisms,
    ) {
    }

    /**
     * @return list<string> organism UUIDs (RFC 4122), deduplicated, nulls removed.
     *                      May be empty (e.g. CTBG missing from the catalogue),
     *                      in which case retrievers apply no boost.
     */
    public function priorityOrganismIdsFor(AccessRequest $accessRequest): array
    {
        $ids = [];

        $garante = $accessRequest->getApplicableLaw()?->getComplaintOrganism();
        if ($garante !== null) {
            $ids[$garante->getId()->toRfc4122()] = true;
        }

        foreach ($this->ctbgIds() as $ctbgId) {
            $ids[$ctbgId] = true;
        }

        return array_keys($ids);
    }

    /** @return list<string> */
    private function ctbgIds(): array
    {
        if ($this->ctbgIdsCache === null) {
            $ctbg = $this->organisms->findOneBy(['shortName' => ComplaintOrganism::SHORT_NAME_CTBG]);
            $this->ctbgIdsCache = $ctbg !== null ? [$ctbg->getId()->toRfc4122()] : [];
        }

        return $this->ctbgIdsCache;
    }
}
