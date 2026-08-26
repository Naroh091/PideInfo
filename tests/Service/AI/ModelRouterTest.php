<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\CustomModelClient;
use App\Service\AI\ModelChoice;
use App\Service\AI\ModelRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ModelRouterTest extends TestCase
{
    private function client(string $model): CustomModelClient
    {
        return new CustomModelClient($model, 'http://localhost/v1', '', 4096, '0.3', new NullLogger());
    }

    private function router(
        string $teacherModel,
        string $teacherEndpoint,
        int $sample,
        bool $enabledForRequests = true,
        bool $enabledForComplaints = true,
        bool $enabledForAlegations = true,
    ): ModelRouter {
        return new ModelRouter(
            $this->client('small'),
            $this->client('teacher'),
            $teacherModel,
            $teacherEndpoint,
            $sample,
            $enabledForRequests,
            $enabledForComplaints,
            $enabledForAlegations,
            new NullLogger(),
        );
    }

    public function testFallsBackToStudentWhenTeacherIsNotConfigured(): void
    {
        $router = $this->router('', '', 100);

        $this->assertFalse($router->isTeacherConfigured());
        $choice = $router->pick(ModelRouter::FEATURE_COMPLAINT);
        $this->assertSame(ModelChoice::ROLE_STUDENT, $choice->role);
        $this->assertFalse($choice->isTeacher());
        $this->assertSame('small', $choice->client->getModel());
    }

    /** Endpoint vacío = teacher inservible; no basta con el nombre del modelo. */
    public function testRequiresBothModelAndEndpoint(): void
    {
        $router = $this->router('teacher', '', 100);

        $this->assertFalse($router->isTeacherConfigured());
        $this->assertSame(ModelChoice::ROLE_STUDENT, $router->pick(ModelRouter::FEATURE_COMPLAINT)->role);
    }

    public function testServesTeacherAtFullSampling(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 100);

        $this->assertTrue($router->isTeacherConfigured());
        $choice = $router->pick(ModelRouter::FEATURE_COMPLAINT);
        $this->assertTrue($choice->isTeacher());
        $this->assertSame('teacher', $choice->client->getModel());
    }

    /** Corte en seco sin redeploy: TEACHER_MODEL_SAMPLE=0. */
    public function testZeroSamplingCutsTheTeacherOff(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 0);

        $this->assertTrue($router->isTeacherConfigured());
        $this->assertSame(ModelChoice::ROLE_STUDENT, $router->pick(ModelRouter::FEATURE_COMPLAINT)->role);
    }

    public function testTeacherAccessorIgnoresSampling(): void
    {
        $router = $this->router('teacher', 'http://teacher/v1', 0);

        $this->assertSame('teacher', $router->teacher()->getModel());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disabledFeatureProvider(): iterable
    {
        yield 'request' => [ModelRouter::FEATURE_REQUEST];
        yield 'complaint' => [ModelRouter::FEATURE_COMPLAINT];
        yield 'alegation' => [ModelRouter::FEATURE_ALEGATION];
        yield 'unknown feature (e.g. consult)' => ['consult'];
    }

    /** Cada feature se activa por separado: apagada su flag, nunca sirve el teacher aunque el muestreo diga que sí. */
    #[DataProvider('disabledFeatureProvider')]
    public function testFeatureFlagGatesTeacherIndependentlyOfSampling(string $feature): void
    {
        $router = $this->router(
            'teacher',
            'http://teacher/v1',
            100,
            enabledForRequests: false,
            enabledForComplaints: false,
            enabledForAlegations: false,
        );

        $this->assertSame(ModelChoice::ROLE_STUDENT, $router->pick($feature)->role);
    }

    /** Activar solo reclamaciones no habilita el teacher para solicitudes ni alegaciones. */
    public function testOnlyEnabledFeatureReachesTeacher(): void
    {
        $router = $this->router(
            'teacher',
            'http://teacher/v1',
            100,
            enabledForRequests: false,
            enabledForComplaints: true,
            enabledForAlegations: false,
        );

        $this->assertTrue($router->pick(ModelRouter::FEATURE_COMPLAINT)->isTeacher());
        $this->assertFalse($router->pick(ModelRouter::FEATURE_REQUEST)->isTeacher());
        $this->assertFalse($router->pick(ModelRouter::FEATURE_ALEGATION)->isTeacher());
    }
}
