<?php

declare(strict_types=1);

namespace App\Eval\Agent;

/**
 * Un caso de la comparación de modelos del agente: una conversación acotada
 * sobre un expediente real, que se ejecuta ENTERA con cada modelo.
 *
 * El expediente tiene que ser real (`requestId`) porque el system prompt lo
 * compilan los composers a partir de la entidad: organismo, ley aplicable,
 * canal, estado y documentos. Un caso sintético compararía a los modelos sobre
 * un contexto que nunca se da en producción.
 *
 * ## Por qué es multi-turno
 *
 * La acción por defecto del agente es `reply`: pregunta. Y en reclamaciones y
 * alegaciones la FASE 1 está forzada por código, así que el primer turno es
 * SIEMPRE un plan, nunca un escrito. Comparar un único turno mediría casi
 * siempre preguntas y planes, no redacción.
 *
 * Pero saltarse esa fase inyectando una aprobación fabricada sería peor: cada
 * modelo propone un plan distinto, así que aprobar un plan sintético haría que
 * ninguno de los dos redactase desde el suyo. Y **qué preguntar es parte de lo
 * que se está midiendo**, no un trámite que estorbe.
 *
 * La solución son los `followUps`: continuaciones escritas a mano, FIJAS e
 * IDÉNTICAS para los dos candidatos. No hay usuario simulado ni LLM haciendo de
 * ciudadano — es un guion. Cada modelo avanza por su propia rama (redacta desde
 * su propio plan), pero la entrada del usuario es la misma para ambos, así que
 * ninguno recibe información que el otro no tenga.
 *
 * @see \App\Command\CompareAgentModelsCommand
 */
final readonly class AgentEvalCase
{
    /**
     * @param list<array<string, mixed>> $history  turnos ya ocurridos, en el formato
     *                                             de {@see \App\Service\AI\Agent\AgentChatOrchestrator::toLlmHistory()}
     * @param list<string>               $followUps continuaciones guionizadas tras el primer turno
     */
    public function __construct(
        public string $id,
        public string $task,
        public string $requestId,
        public string $userMessage,
        public array $followUps = [],
        public array $history = [],
        public string $notes = '',
    ) {
    }

    /** Turnos que se ejecutarán: el inicial más cada continuación del guion. */
    public function turnCount(): int
    {
        return 1 + count($this->followUps);
    }

    /** @return list<string> mensajes del usuario, en orden */
    public function userMessages(): array
    {
        return [$this->userMessage, ...$this->followUps];
    }

    public function flow(): string
    {
        return $this->task === 'request' ? 'request' : 'complaint';
    }
}
