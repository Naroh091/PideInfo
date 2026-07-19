<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\ComplaintOrganism;
use App\Repository\ComplaintOrganismRepository;
use App\Service\AI\DoctrinePriorityResolver;
use PHPUnit\Framework\TestCase;

final class DoctrinePriorityResolverTest extends TestCase
{
    public function testGaranteAndCtbgBothIncluded(): void
    {
        $ctbg = $this->organism(ComplaintOrganism::SHORT_NAME_CTBG);
        $ctg = $this->organism('CTG');

        $resolver = $this->resolver($ctbg);
        $ids = $resolver->priorityOrganismIdsFor($this->requestFor($ctg));

        $this->assertEqualsCanonicalizing(
            [$ctg->getId()->toRfc4122(), $ctbg->getId()->toRfc4122()],
            $ids,
        );
    }

    public function testGaranteIsCtbgIsDeduplicated(): void
    {
        $ctbg = $this->organism(ComplaintOrganism::SHORT_NAME_CTBG);

        $resolver = $this->resolver($ctbg);
        $ids = $resolver->priorityOrganismIdsFor($this->requestFor($ctbg));

        $this->assertSame([$ctbg->getId()->toRfc4122()], $ids);
    }

    public function testNoGaranteFallsBackToCtbgOnly(): void
    {
        $ctbg = $this->organism(ComplaintOrganism::SHORT_NAME_CTBG);

        $resolver = $this->resolver($ctbg);
        // ApplicableLaw with no complaintOrganism.
        $ids = $resolver->priorityOrganismIdsFor($this->requestFor(null));

        $this->assertSame([$ctbg->getId()->toRfc4122()], $ids);
    }

    public function testCtbgMissingYieldsGaranteOnly(): void
    {
        $ctg = $this->organism('CTG');

        $resolver = $this->resolver(null); // CTBG not in the catalogue
        $ids = $resolver->priorityOrganismIdsFor($this->requestFor($ctg));

        $this->assertSame([$ctg->getId()->toRfc4122()], $ids);
    }

    private function resolver(?ComplaintOrganism $ctbg): DoctrinePriorityResolver
    {
        $repo = $this->createMock(ComplaintOrganismRepository::class);
        $repo->method('findOneBy')
            ->with(['shortName' => ComplaintOrganism::SHORT_NAME_CTBG])
            ->willReturn($ctbg);

        return new DoctrinePriorityResolver($repo);
    }

    private function organism(string $shortName): ComplaintOrganism
    {
        return (new ComplaintOrganism())->setName($shortName)->setShortName($shortName);
    }

    private function requestFor(?ComplaintOrganism $garante): AccessRequest
    {
        $law = (new ApplicableLaw())->setComplaintOrganism($garante);
        $request = new AccessRequest();
        $request->setApplicableLaw($law);

        return $request;
    }
}
