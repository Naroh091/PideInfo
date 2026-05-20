<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Repository\AgentTaskRepository;
use App\Service\AccessRequest\SubmissionGuard;
use PHPUnit\Framework\TestCase;

class SubmissionGuardTest extends TestCase
{
    private function guard(?AgentTask $active): SubmissionGuard
    {
        $tasks = $this->createMock(AgentTaskRepository::class);
        $tasks->method('findNonTerminalForRequest')->willReturn($active);
        return new SubmissionGuard($tasks);
    }

    private function request(array $metadata): AccessRequest
    {
        $request = $this->createMock(AccessRequest::class);
        $request->method('getMetadataValue')->willReturnCallback(
            fn(string $key) => $metadata[$key] ?? null
        );
        return $request;
    }

    public function testBlocksWhenAnActiveTaskExists(): void
    {
        $decision = $this->guard($this->createMock(AgentTask::class))
            ->evaluate($this->request([]), AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, false);

        self::assertFalse($decision->allowed);
        self::assertSame('active_task', $decision->reason);
    }

    public function testRegUncertainNeedsConfirmation(): void
    {
        $request = $this->request([
            'submission_uncertain' => ['channel' => AgentTask::TYPE_SUBMIT_REQUEST_REG],
        ]);
        $decision = $this->guard(null)
            ->evaluate($request, AgentTask::TYPE_SUBMIT_REQUEST_REG, false);

        self::assertFalse($decision->allowed);
        self::assertSame('uncertain_needs_confirmation', $decision->reason);
    }

    public function testRegUncertainAllowedOnceConfirmed(): void
    {
        $request = $this->request([
            'submission_uncertain' => ['channel' => AgentTask::TYPE_SUBMIT_REQUEST_REG],
        ]);
        $decision = $this->guard(null)
            ->evaluate($request, AgentTask::TYPE_SUBMIT_REQUEST_REG, true);

        self::assertTrue($decision->allowed);
    }

    public function testTransparenciaUncertainSelfHealsAndCarriesIdBorr(): void
    {
        $request = $this->request([
            'submission_uncertain' => ['channel' => AgentTask::TYPE_SUBMIT_REQUEST_PORTAL],
            'portal_markers' => [
                AgentTask::TYPE_SUBMIT_REQUEST_PORTAL => ['idBorr' => '531503'],
            ],
        ]);
        $decision = $this->guard(null)
            ->evaluate($request, AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, false);

        self::assertTrue($decision->allowed, 'transparencia no se bloquea: reconcilia vía idBorr');
        self::assertSame('531503', $decision->reconcileIdBorr);
    }

    public function testFreshRequestIsAllowedWithNoMarker(): void
    {
        $decision = $this->guard(null)
            ->evaluate($this->request([]), AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, false);

        self::assertTrue($decision->allowed);
        self::assertNull($decision->reconcileIdBorr);
    }
}
