<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

use App\Observability\TracePayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Volcado opcional de la conversación COMPLETA de cada turno agéntico
 * (system prompt + historial + tool calls con sus resultados + decisión final)
 * a ficheros JSONL, uno por TAREA y día.
 *
 * Motivación: la telemetría de Langfuse del agente guarda solo resúmenes
 * (`{"messages": N, "tools": M}`), inutilizables como trazas de entrenamiento.
 *
 * El fichero se parte por tarea, no por flujo: reclamaciones y respuestas a
 * alegaciones comparten `flow = complaint` pero son tareas DISTINTAS (redactar
 * una reclamación no es rebatir punto por punto un escrito de alegaciones), y
 * mezclarlas en el mismo corpus degrada las dos.
 *
 * Cada línea lleva `messages` más los metadatos de reproducibilidad
 * ({@see AgentTurnTrace}). El formato `{"messages": [...]}` que consume la vía
 * traces→model de Distil se obtiene proyectando estas líneas, no leyéndolas
 * directamente.
 *
 * Desactivado por defecto: solo escribe si AGENT_TRACE_CAPTURE_DIR apunta a un
 * directorio. Nunca rompe el turno: cualquier fallo se degrada a un warning.
 *
 * AVISO: el volcado contiene expedientes reales de ciudadanos (documentos,
 * datos personales, resoluciones que les afectan) en texto plano. El directorio
 * debe tratarse con el mismo cuidado que la base de datos: acceso restringido,
 * retención acotada y decisión explícita sobre si entra en backups.
 */
final class AgentTurnTraceCapture
{
    public function __construct(
        #[Autowire(env: 'AGENT_TRACE_CAPTURE_DIR')]
        private readonly string $dir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->dir !== '';
    }

    public function capture(AgentTurnTrace $trace): void
    {
        if ($this->dir === '') {
            return;
        }

        try {
            $messages = TracePayload::sanitizeMessages($trace->messages);

            // Último turno = lo que produjo el modelo. Con decisión válida va el
            // JSON ya validado; si el turno murió por JSON inválido guardamos la
            // salida cruda (es justo el fallo que queremos poder estudiar); si
            // ni siquiera hubo respuesta (error de red) no se añade nada.
            if ($trace->decision !== []) {
                $messages[] = [
                    'role'    => 'assistant',
                    'content' => json_encode($trace->decision, JSON_UNESCAPED_UNICODE),
                ];
            } elseif ($trace->rawOutput !== '') {
                $messages[] = ['role' => 'assistant', 'content' => $trace->rawOutput];
            }

            $line = json_encode(
                [
                    'turn_id'   => bin2hex(random_bytes(8)),
                    'ts'        => gmdate('c'),
                    'task'      => $trace->task,
                    'flow'      => $trace->flow,
                    'entity_id' => $trace->entityId,
                    'status'    => $trace->status,
                    'nudged'    => $trace->nudged,
                    'model'     => [
                        'role'        => $trace->modelRole,
                        'name'        => $trace->modelName,
                        'temperature' => $trace->temperature,
                    ],
                    'prompt'    => [
                        'name'    => $trace->promptName,
                        'version' => $trace->promptVersion,
                    ],
                    'messages'  => $messages,
                ],
                // ASCII escapado: el parser de la plataforma trata U+2028/U+2029
                // como fin de línea y truncaría el registro en silencio.
                JSON_THROW_ON_ERROR,
            );

            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0775, true);
            }
            $file = sprintf('%s/agent-%s-%s.jsonl', rtrim($this->dir, '/'), $trace->task, date('Y-m-d'));
            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            $this->logger->warning('AgentTurnTraceCapture failed', [
                'task'  => $trace->task,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
