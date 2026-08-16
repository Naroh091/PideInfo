<?php

declare(strict_types=1);

namespace App\Service\AI;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decide con qué modelo se sirve cada turno agéntico: el modelo pequeño de
 * siempre (CUSTOM_MODEL) o el TEACHER.
 *
 * Cuando el teacher está activo su salida es la que recibe el usuario — no es
 * un shadow. El objetivo es recoger trazas de la calidad que queremos destilar,
 * no de la que ya tenemos: entrenar con las salidas del modelo pequeño sería
 * clonar exactamente el comportamiento que se pretende mejorar.
 *
 * Se activa solo si TEACHER_MODEL y TEACHER_MODEL_ENDPOINT están puestos.
 * TEACHER_MODEL_SAMPLE (0-100) permite bajar el porcentaje o cortar en seco sin
 * redeploy. El rol elegido se guarda en cada traza, así que un corpus recogido
 * durante un cambio de porcentaje sigue siendo separable a posteriori.
 */
final class ModelRouter
{
    public function __construct(
        private readonly CustomModelClient $studentClient,
        #[Autowire(service: 'app.custom_model_client.teacher')]
        private readonly CustomModelClient $teacherClient,
        #[Autowire(env: 'TEACHER_MODEL')]
        private readonly string $teacherModel,
        #[Autowire(env: 'TEACHER_MODEL_ENDPOINT')]
        private readonly string $teacherEndpoint,
        #[Autowire(env: 'int:TEACHER_MODEL_SAMPLE')]
        private readonly int $samplePercent,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isTeacherConfigured(): bool
    {
        return $this->teacherModel !== '' && $this->teacherEndpoint !== '';
    }

    /**
     * Cliente del teacher, SIN muestreo. Lo usa {@see \App\Service\AI\Llm\LlmClient}
     * para despachar peticiones que ya vienen marcadas: el sorteo lo hace quien
     * llama, con {@see pick()}, una sola vez, para que el rol que se guarda en la
     * traza sea exactamente el que sirvió la generación.
     */
    public function teacher(): CustomModelClient
    {
        return $this->teacherClient;
    }

    /**
     * Cliente del modelo pequeño (el que sirve hoy), SIN muestreo. Lo usa la
     * comparación offline, que necesita cada modelo por separado en vez de
     * dejar que decida el sorteo.
     */
    public function student(): CustomModelClient
    {
        return $this->studentClient;
    }

    /**
     * Elige el modelo de este turno. Se llama UNA vez por turno y el resultado
     * se usa en todas las llamadas del turno.
     */
    public function pick(): ModelChoice
    {
        if (!$this->isTeacherConfigured() || $this->samplePercent <= 0) {
            return new ModelChoice($this->studentClient, ModelChoice::ROLE_STUDENT);
        }

        if ($this->samplePercent < 100 && random_int(1, 100) > $this->samplePercent) {
            return new ModelChoice($this->studentClient, ModelChoice::ROLE_STUDENT);
        }

        return new ModelChoice($this->teacherClient, ModelChoice::ROLE_TEACHER);
    }

    /**
     * Aviso de arranque: temperatura alta en el teacher produce trazas poco
     * reproducibles (dos recogidas del mismo caso divergen), que es justo lo
     * contrario de lo que se busca al montar la recogida.
     */
    public function warnIfMisconfigured(): void
    {
        if ($this->isTeacherConfigured() && $this->teacherClient->getTemperature() > 0.7) {
            $this->logger->warning('TEACHER_MODEL_TEMP alto para recogida de trazas', [
                'temperature' => $this->teacherClient->getTemperature(),
            ]);
        }
    }
}
