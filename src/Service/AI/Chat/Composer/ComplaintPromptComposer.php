<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;

/**
 * Composes the system prompt for the complaint flow of the unified chat
 * assistant. Delegates the legal scaffolding (criteria, resolutions, silence
 * directives, alegation-response framing) to {@see ComplaintGenerator::composeChatScaffolding}
 * and prepends a decision-marker policy on top.
 *
 * The underlying complaint prompt instructs the model to emit full HTML.
 * Our wrapper EXPLICITLY OVERRIDES that contract: first chat reply, then
 * `===DECISION===`, then JSON `{action, draft?: {title, body_html}}`. We
 * shout this loudly so the legal-scaffolding tail doesn't win the wrestling
 * match over output format.
 */
final class ComplaintPromptComposer
{
    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
    ) {
    }

    /**
     * @param array<array{name: string, type: string, content: string}> $documentContents
     */
    public function compose(AccessRequest $ar, string $mode, string $currentBodyHtml, array $documentContents = []): CompiledPrompt
    {
        $isAlegation = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE;
        $scaffolding = $this->complaintGenerator->composeChatScaffolding($ar, $mode, $documentContents);

        $hasDraft = trim(strip_tags($currentBodyHtml)) !== '';
        $state = $hasDraft ? 'YA EXISTE un borrador en el canvas.' : 'Aún no hay borrador.';
        $verb = $isAlegation ? 'la respuesta a las alegaciones' : 'la reclamación';

        $policy = <<<TXT
# IMPORTANTE: protocolo de salida del chat asistente

Esta conversación se entrega al usuario en una interfaz de chat con canvas. **DEBES OBEDECER el siguiente protocolo de salida**, que SUSTITUYE cualquier instrucción posterior sobre el formato de respuesta:

## Política de decisión

En cada turno decides UNA de tres acciones:

1. `reply` — el usuario pregunta, falta contexto clave o aún no procede generar.
2. `generate` — el borrador está vacío y procede emitirlo por primera vez.
3. `rewrite` — ya hay borrador y el usuario pide un cambio concreto.

Estado actual: {$state}

## Formato de salida (OBLIGATORIO)

Primero escribe la respuesta conversacional al usuario, en español, natural y breve. Una o dos frases — explica brevemente qué vas a hacer (generar, reescribir) o pregunta lo que necesites saber.

A continuación, en una **línea aparte**, escribe literalmente la marca:

===DECISION===

Y debajo, un único bloque JSON válido (sin code fences) con la forma:

{
  "action": "reply" | "generate" | "rewrite",
  "draft": {
    "title": "Asunto breve (≤255)",
    "body_html": "HTML completo de {$verb}, mismo formato que las instrucciones de scaffolding."
  }
}

Reglas estrictas:
- Si action == "reply", **OMITE** la clave "draft" por completo.
- Si action == "generate" o "rewrite", "body_html" contiene {$verb} ENTERA en HTML siguiendo las pautas de la sección "## Scaffolding del flujo de reclamaciones". Devuelve el documento completo, NO un parche.
- "title" puede ser breve y descriptivo del escrito (no del título del expediente).
- Sin texto fuera del JSON después de la marca.
- Las instrucciones posteriores te dirán cómo redactar el HTML; este protocolo describe cómo entregarlo.

TXT;

        $borradorBlock = $hasDraft
            ? "\n\n## Borrador actual en el canvas (PUNTO DE PARTIDA para `rewrite`)\n\n{$currentBodyHtml}\n"
            : '';

        $fullText = $policy . $borradorBlock . "\n\n## Scaffolding del flujo de reclamaciones\n\n" . $scaffolding->text;

        return new CompiledPrompt(text: $fullText, name: $scaffolding->name, version: $scaffolding->version);
    }
}
