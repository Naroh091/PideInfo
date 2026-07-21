<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\Complaint\ComplaintDraftGenerator;

/**
 * Lightweight per-turn classifier for the free-consultation chat: decides whether
 * the user is asking to DRAFT (or keep drafting/adjusting) a reclamación or a
 * respuesta a alegaciones — the two document kinds that have a dedicated, higher
 * quality pipeline. When it fires, {@see \App\Controller\AssistantChatController::consult()}
 * hands the turn off to the specialized complaint composer instead of the general
 * consult prompt.
 *
 * It is context-aware (receives the last turns) so it keeps the same intent across
 * the complaint FASE 1 → aprobación → FASE 2 sequence (a bare "sí, adelante" after
 * a reclamación plan still classifies as `complaint`).
 */
final class ConsultIntentClassifier
{
    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['intent'],
        'properties'           => [
            'intent' => [
                'type'        => 'string',
                'enum'        => ['complaint', 'alegation_response', 'other'],
                'description' => 'complaint = redactar/ajustar una reclamación; alegation_response = redactar/ajustar una respuesta a las alegaciones de la Administración; other = cualquier otra cosa (duda, otro escrito, comentario).',
            ],
        ],
    ];

    private const SYSTEM = <<<'TXT'
Eres un clasificador de intención dentro de un chat de asistencia sobre un expediente de acceso a la información pública. A partir de la conversación reciente y del último mensaje del usuario, decide qué está pidiendo:

- `complaint`: redactar (o seguir ajustando/confirmar) una **reclamación** ante el consejo de transparencia.
- `alegation_response`: redactar (o seguir ajustando/confirmar) una **respuesta a las alegaciones** de la Administración en una reclamación.
- `other`: cualquier otra cosa — una duda, otro tipo de escrito (subsanación, recordatorio, doc a medida), o un comentario.

Fíjate en la INTENCIÓN de redactar ese escrito concreto, no en el mero tema:
- "¿cómo hago una reclamación?" / "¿me conviene reclamar?" → `other` (es una duda).
- "hazme la reclamación" / "redacta la reclamación" / "reclama por el silencio" → `complaint`.
- "responde a las alegaciones" / "rebate lo que alegan" → `alegation_response`.
- Si en los turnos previos ya se estaba redactando/planificando una reclamación o una respuesta a alegaciones y el usuario confirma ("sí", "adelante", "perfecto") o pide un ajuste, MANTÉN esa intención (`complaint` o `alegation_response`).

Responde SOLO con el JSON de la clasificación.
TXT;

    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * @return ComplaintDraftGenerator::MODE_*|'other'
     */
    public function classify(string $userMessage, string $recentContext = ''): string
    {
        if (trim($userMessage) === '') {
            return 'other';
        }

        $userText = ($recentContext !== '' ? "Conversación reciente:\n{$recentContext}\n\n" : '')
            . "Último mensaje del usuario:\n{$userMessage}";

        try {
            $data = $this->llm->chatJson(new ChatRequest(
                systemPrompt: self::SYSTEM,
                userText: $userText,
                temperature: 0.0,
                jsonSchema: self::SCHEMA,
                maxOutputTokens: 60,
                label: 'consult-intent',
                traceName: 'ConsultIntentClassifier',
            ));
        } catch (\Throwable) {
            // Never block the turn on the classifier: fall back to the general flow.
            return 'other';
        }

        $intent = is_string($data['intent'] ?? null) ? $data['intent'] : 'other';

        return match ($intent) {
            'complaint'          => ComplaintDraftGenerator::MODE_COMPLAINT,
            'alegation_response' => ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE,
            default              => 'other',
        };
    }
}
