<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\CustomModelClient;
use App\Service\AI\ModelChoice;
use App\Service\AI\ModelRouter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ModelRouterTest extends TestCase
{
    private function client(string $model): CustomModelClient
    {
        return new CustomModelClient($model, 'http://localhost/v1', '', 4096, 0.3, new NullLogger());
    }

    private function router(string $teacherModel, string $teacherEndpoint, int $sample): ModelRouter
    {
        return new ModelRouter(
            $this->client('small'),
            $this->client('teacher'),
            $teacherModel,
            $teacherEndpoint,
            $sample,
            new NullLogger(),
        );
    }

    public function testFallsBackToStudentWhenTeacherIsNotConfigured(): void
    {
        $router = $this->router('', '', 100);

        $this->assertFalse($router->isTeacherConfigured());
        $choice = $router->pick();
        $this->assertSame(ModelChoice::ROLE_STUDENT, $choice->role);
        $this->assertFalse($choice->isTeacher());
        $this->assertSame('small', $choice->client->getModel());
    }

    /** Endpoint vacío = teacher inservible; no basta con el nombre del modelo. */
    public function testRequiresBothModelAndEndpoint(): void
    {
        $router = $this->router('teacher', '', 100);

        $this->assertFalse($router->isTeacherConfigured());
        $this->assertSame(ModelChoice::ROLE_STUDENT, $router->pick()->role);
    }

    public function testServesTeacherAtFullSampling(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 100);

        $this->assertTrue($router->isTeacherConfigured());
        $choice = $router->pick();
        $this->assertTrue($choice->isTeacher());
        $this->assertSame('teacher', $choice->client->getModel());
    }

    /** Corte en seco sin redeploy: TEACHER_MODEL_SAMPLE=0. */
    public function testZeroSamplingCutsTheTeacherOff(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 0);

        $this->assertTrue($router->isTeacherConfigured());
        $this->assertSame(ModelChoice::ROLE_STUDENT, $router->pick()->role);
    }

    public function testTeacherAccessorIgnoresSampling(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 0);

        $this->assertSame('teacher', $router->teacher()->getModel());
    }
}
