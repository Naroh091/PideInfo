<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * isDeadlinePassed() señala silencio administrativo, así que debe apagarse en
 * cuanto hay una decisión expresa. Tras el rediseño la decisión vive en
 * `resolutionResult` (no en `status`): cualquier resultado salvo el silencio
 * cuenta como respuesta — incluidas la estimación parcial y la inadmisión.
 */
final class AccessRequestDeadlineTest extends TestCase
{
    private function expiredRequestWithResolution(?string $resolutionResult): AccessRequest
    {
        $request = new AccessRequest();
        $request->setStatus(AccessRequest::STATUS_FINISHED);
        $request->setResolutionResult($resolutionResult);
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));

        return $request;
    }

    /** @return iterable<string, array{string}> */
    public static function respondedResolutions(): iterable
    {
        yield 'granted' => [AccessRequest::RESULT_GRANTED];
        yield 'partially_granted' => [AccessRequest::RESULT_PARTIALLY_GRANTED];
        yield 'denied' => [AccessRequest::RESULT_DENIED];
        yield 'inadmitted' => [AccessRequest::RESULT_INADMITTED];
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedStatuses(): iterable
    {
        yield 'sent' => [AccessRequest::STATUS_SENT];
        yield 'processing' => [AccessRequest::STATUS_PROCESSING];
        yield 'pending' => [AccessRequest::STATUS_PENDING];
        yield 'delayed' => [AccessRequest::STATUS_DELAYED];
    }

    #[DataProvider('respondedResolutions')]
    public function testExplicitDecisionMeansDeadlineIsNotPassed(string $resolutionResult): void
    {
        self::assertFalse($this->expiredRequestWithResolution($resolutionResult)->isDeadlinePassed());
    }

    /** El silencio inferido NO es una respuesta: el plazo sigue vencido. */
    public function testSilenceResolutionStillCountsAsDeadlinePassed(): void
    {
        self::assertTrue($this->expiredRequestWithResolution(AccessRequest::RESULT_SILENCE)->isDeadlinePassed());
    }

    #[DataProvider('unresolvedStatuses')]
    public function testUnresolvedExpiredRequestHasDeadlinePassed(string $status): void
    {
        $request = new AccessRequest();
        $request->setStatus($status);
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));

        self::assertTrue($request->isDeadlinePassed());
    }

    public function testFutureDeadlineIsNotPassed(): void
    {
        $request = new AccessRequest();
        $request->setStatus(AccessRequest::STATUS_SENT);
        $request->setDeadlineAt(new \DateTimeImmutable('+10 days'));

        self::assertFalse($request->isDeadlinePassed());
    }
}
