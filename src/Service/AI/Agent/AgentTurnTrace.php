<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

/**
 * Un turno agéntico listo para volcarse como traza de entrenamiento.
 *
 * Agrupa la conversación con los metadatos que hacen la traza REPRODUCIBLE:
 * qué modelo la produjo (teacher o el pequeño), con qué temperatura, sobre qué
 * versión del prompt gestionado en Langfuse, y si el turno terminó bien o murió
 * en una validación. Sin esos campos un corpus recogido a lo largo de semanas
 * es inservible: no se puede saber qué líneas vienen de qué modelo ni de qué
 * versión del prompt, y los cambios de prompt son invisibles.
 *
 * @see AgentTurnTraceCapture
 */
final readonly class AgentTurnTrace
{
    /** Turno completado: el modelo devolvió una decisión válida. */
    public const STATUS_OK = 'ok';
    /** La llamada final al modelo falló (red, timeout, 5xx). */
    public const STATUS_LLM_ERROR = 'llm_error';
    /** El modelo respondió algo que no era JSON. */
    public const STATUS_INVALID_JSON = 'invalid_json';
    /** `action` fuera del enum permitido. */
    public const STATUS_INVALID_ACTION = 'invalid_action';
    /** `action` = generate/rewrite pero sin objeto `draft`. */
    public const STATUS_MISSING_DRAFT = 'missing_draft';

    /**
     * @param list<array<string, mixed>> $messages conversación en formato OpenAI
     * @param array<string, mixed>       $decision decisión final validada ([] si el turno murió antes)
     * @param string                     $rawOutput salida cruda cuando no se pudo decodificar ('' si no aplica)
     */
    public function __construct(
        public string $task,
        public string $flow,
        public string $entityId,
        public array $messages,
        public array $decision = [],
        public string $status = self::STATUS_OK,
        public string $rawOutput = '',
        public string $modelRole = 'student',
        public string $modelName = '',
        public ?float $temperature = null,
        public ?string $promptName = null,
        public ?int $promptVersion = null,
        /**
         * True cuando se disparó el nudge anti-re-plan del flujo complaint. La
         * conversación guardada es la LIMPIA (sin los dos turnos sintéticos que
         * el nudge inyecta), pero la marca permite filtrar o revisar estos casos:
         * la decisión final salió de un segundo intento forzado, no del primero.
         */
        public bool $nudged = false,
    ) {
    }
}
