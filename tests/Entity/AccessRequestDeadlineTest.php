<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * isDeadlinePassed() señala silencio administrativo, así que debe apagarse en
 * cuanto hay una decisión expresa — cualquiera de los estados que cubre
 * hasReceivedResponse(), incluidas la estimación parcial y la inadmisión.
 * Una parcial con el plazo original vencido NO está en silencio.
 */
final class AccessRequestDeadlineTest extends TestCase
{
    private function expiredRequest(string $status): AccessRequest
    {
        $request = new AccessRequest();
        $request->setStatus($status);
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));

        return $request;
    }

    /** @return iterable<string, array{string}> */
    public static function respondedStatuses(): iterable
    {
        yield 'granted' => [AccessRequest::STATUS_GRANTED];
        yield 'granted_completed' => [AccessRequest::STATUS_GRANTED_COMPLETED];
        yield 'partially_granted' => [AccessRequest::STATUS_PARTIALLY_GRANTED];
        yield 'denied' => [AccessRequest::STATUS_DENIED];
        yield 'inadmitted' => [AccessRequest::STATUS_INADMITTED];
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedStatuses(): iterable
    {
        yield 'sent' => [AccessRequest::STATUS_SENT];
        yield 'processing' => [AccessRequest::STATUS_PROCESSING];
        yield 'pending' => [AccessRequest::STATUS_PENDING];
        yield 'delayed' => [AccessRequest::STATUS_DELAYED];
    }

    #[DataProvider('respondedStatuses')]
    public function testExplicitDecisionMeansDeadlineIsNotPassed(string $status): void
    {
        self::assertFalse($this->expiredRequest($status)->isDeadlinePassed());
    }

    #[DataProvider('unresolvedStatuses')]
    public function testUnresolvedExpiredRequestHasDeadlinePassed(string $status): void
    {
        self::assertTrue($this->expiredRequest($status)->isDeadlinePassed());
    }

    public function testFutureDeadlineIsNotPassed(): void
    {
        $request = new AccessRequest();
        $request->setStatus(AccessRequest::STATUS_SENT);
        $request->setDeadlineAt(new \DateTimeImmutable('+10 days'));

        self::assertFalse($request->isDeadlinePassed());
    }
}
