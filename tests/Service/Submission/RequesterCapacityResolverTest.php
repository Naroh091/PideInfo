<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Service\Submission\RequesterCapacity;
use App\Service\Submission\RequesterCapacityResolver;
use PHPUnit\Framework\TestCase;

final class RequesterCapacityResolverTest extends TestCase
{
    private RequesterCapacityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RequesterCapacityResolver();
    }

    private function requestOf(?User $user): AccessRequest
    {
        $request = new AccessRequest();

        if ($user !== null) {
            $request->setUser($user);
        }

        return $request;
    }

    public function testFallsBackToCitizen(): void
    {
        $resolved = $this->resolver->for($this->requestOf(new User()));

        self::assertSame(RequesterCapacity::CITIZEN, $resolved['capacity']);
        self::assertSame('default', $resolved['source']);
        self::assertNull($resolved['detail']);
    }

    public function testTakesTheCapacityFromTheProfile(): void
    {
        $user = (new User())
            ->setRequesterCapacity(RequesterCapacity::ELECTED_OFFICIAL)
            ->setRequesterCapacityDetail('Concejal del Ayuntamiento de Getafe');

        $resolved = $this->resolver->for($this->requestOf($user));

        self::assertSame(RequesterCapacity::ELECTED_OFFICIAL, $resolved['capacity']);
        self::assertSame('Concejal del Ayuntamiento de Getafe', $resolved['detail']);
        self::assertSame('profile', $resolved['source']);
    }

    public function testTheRequestOverridesTheProfile(): void
    {
        // A concejal may perfectly well file a request as a private citizen. The per-request
        // value is how they say so, and it has to win.
        $user = (new User())->setRequesterCapacity(RequesterCapacity::ELECTED_OFFICIAL);

        $request = $this->requestOf($user);
        $request->setMetadataValue(RequesterCapacityResolver::METADATA_KEY, RequesterCapacity::CITIZEN);

        $resolved = $this->resolver->for($request);

        self::assertSame(RequesterCapacity::CITIZEN, $resolved['capacity']);
        self::assertSame('request', $resolved['source']);
    }

    public function testACorruptValueDegradesToCitizenInsteadOfBlowingUp(): void
    {
        $user = (new User())->setRequesterCapacity('alcalde_galactico');

        $resolved = $this->resolver->for($this->requestOf($user));

        self::assertSame(RequesterCapacity::CITIZEN, $resolved['capacity']);
        self::assertSame('default', $resolved['source']);
    }

    public function testDetailIsTrimmedAndCapped(): void
    {
        $user = (new User())->setRequesterCapacityDetail('  ' . str_repeat('x', 200) . '  ');

        self::assertSame(160, mb_strlen((string) $user->getRequesterCapacityDetail()));

        $user->setRequesterCapacityDetail('   ');
        self::assertNull($user->getRequesterCapacityDetail());
    }

    public function testOnlyTheCapacitiesQuotedInTheHeadingAskForDetail(): void
    {
        self::assertTrue(RequesterCapacity::needsDetail(RequesterCapacity::ELECTED_OFFICIAL));
        self::assertTrue(RequesterCapacity::needsDetail(RequesterCapacity::ORGANISATION));
        self::assertFalse(RequesterCapacity::needsDetail(RequesterCapacity::CITIZEN));
        self::assertFalse(RequesterCapacity::needsDetail(RequesterCapacity::JOURNALIST));
    }

    public function testChoicesAreLabelToKey(): void
    {
        $choices = RequesterCapacity::choices();

        self::assertSame(RequesterCapacity::ELECTED_OFFICIAL, $choices['Concejal/a o cargo electo']);
        self::assertCount(count(RequesterCapacity::all()), $choices);
    }
}
