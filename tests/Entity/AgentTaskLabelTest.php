<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AgentTask;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AgentTaskLabelTest extends TestCase
{
    private function task(string $type = AgentTask::TYPE_SUBMIT_REQUEST_PORTAL): AgentTask
    {
        return new AgentTask(new User(), $type);
    }

    /**
     * @dataProvider statusLabelProvider
     */
    public function testStatusLabel(string $status, string $expected): void
    {
        self::assertSame($expected, $this->task()->setStatus($status)->getStatusLabel());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statusLabelProvider(): iterable
    {
        yield 'pending' => [AgentTask::STATUS_PENDING, 'En curso'];
        yield 'claimed' => [AgentTask::STATUS_CLAIMED, 'En curso'];
        yield 'in_progress' => [AgentTask::STATUS_IN_PROGRESS, 'En curso'];
        yield 'done' => [AgentTask::STATUS_DONE, 'Hecho'];
        yield 'failed' => [AgentTask::STATUS_FAILED, 'Fallido'];
        yield 'uncertain' => [AgentTask::STATUS_UNCERTAIN, 'Sin confirmar'];
    }

    /**
     * @dataProvider typeLabelProvider
     */
    public function testTypeLabel(string $type, string $expected): void
    {
        self::assertSame($expected, $this->task($type)->getTypeLabel());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeLabelProvider(): iterable
    {
        yield 'portal' => [AgentTask::TYPE_SUBMIT_REQUEST_PORTAL, 'Portal de Transparencia'];
        yield 'reg' => [AgentTask::TYPE_SUBMIT_REQUEST_REG, 'Registro Electrónico (REG)'];
        yield 'complaint' => [AgentTask::TYPE_PRESENT_COMPLAINT, 'Reclamación (CTBG)'];
        yield 'complaint reg' => [AgentTask::TYPE_PRESENT_COMPLAINT_REG, 'Reclamación (REG)'];
    }

    public function testTypeLabelFallsBackToRawTypeForUnknownTypes(): void
    {
        self::assertSame('quien_sabe', $this->task('quien_sabe')->getTypeLabel());
    }

    public function testNewTaskIsPendingAndReadsAsInProgress(): void
    {
        $task = $this->task();

        self::assertSame(AgentTask::STATUS_PENDING, $task->getStatus());
        self::assertSame('En curso', $task->getStatusLabel());
        self::assertFalse($task->isTerminal());
    }
}
