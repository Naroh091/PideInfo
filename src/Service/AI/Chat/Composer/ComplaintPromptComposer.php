<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\AI\Chat\WritingPreferencesFormatter;
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
        private readonly LegalFrameworkComposer $legalFramework,
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
        $state = $hasDraft
            ? 'YA EXISTE un borrador en el canvas.'
            : 'Aún NO hay borrador → salvo que el historial muestre un plan ya confirmado por el usuario, tu acción DEBE ser `reply` (FASE 1).';
        $verb = $isAlegation ? 'la respuesta a las alegaciones' : 'la reclamación';

        // The two-phase block is framed differently for each mode: a complaint
        // dismantles the Administration's denial; an alegation-response rebuts the
        // Administration's allegations (filed after the citizen's complaint) one by one.
        $twoPhaseComplaint = <<<TXT
## Protocolo OBLIGATORIO en dos fases (cuando AÚN NO hay borrador)

**FASE 1 — Identificar argumentos y proponer un plan (action = `reply`).**
1. Lee los documentos del expediente con `read_request_documents` (la resolución/denegación y, sobre todo, las ALEGACIONES de la Administración: ahí están los argumentos a rebatir).
2. Identifica TODOS y CADA UNO de los argumentos que la Administración ha esgrimido (causas de inadmisión del art. 18, límites del art. 14, autonomía del órgano, "información ya publicada", carácter no vinculante, etc.). No te dejes ninguno.
3. Responde al usuario (action = `reply`, SIN `draft`) así: en `conversational_reply` una introducción BREVE (p. ej. "He analizado el expediente. Estos son los argumentos de la Administración y cómo los desmontaré:") y, al final, la pregunta de confirmación ("¿Te parece bien este enfoque o quieres que añada/quite algo antes de redactar?"). El detalle va en el campo `plan`: un elemento por cada argumento, con `argument` (qué alega la Administración) y `strategy` (cómo lo vas a desmontar). El cliente mostrará cada elemento como una tarjeta, así que NO repitas el listado dentro de `conversational_reply`.
4. En FASE 1 **NO llames a `search_resolutions`** todavía y **NO escribas el borrador**. Solo lee documentos, identifica argumentos y propón el plan.

**FASE 2 — Redactar (action = `generate`).**
SOLO cuando, en un turno ANTERIOR, ya hayas hecho la FASE 1 y el usuario haya confirmado el plan (p. ej. "ok", "adelante", "perfecto", "genera", o proponiéndote ajustes concretos): busca doctrina con `search_resolutions` Y `search_criteria` (una llamada de cada por argumento confirmado) y emite el borrador completo.
TXT;

        $twoPhaseAlegation = <<<TXT
## Protocolo OBLIGATORIO en dos fases (cuando AÚN NO hay borrador)

Estás redactando la RESPUESTA A LAS ALEGACIONES de la Administración: el ciudadano YA presentó su reclamación y la Administración ha contestado con un escrito de ALEGACIONES defendiendo su negativa. Tu trabajo es REBATIR esas alegaciones una a una, NO redactar una reclamación nueva desde cero ni reproducir su estructura.

**FASE 1 — Identificar las alegaciones y proponer un plan de réplica (action = `reply`).**
1. Lee con `read_request_documents` el escrito de ALEGACIONES de la Administración (es el documento CLAVE) y la reclamación original del ciudadano, para saber a qué responde cada alegación.
2. Identifica TODAS y CADA UNA de las alegaciones concretas que la Administración esgrime en ese escrito (p. ej. autonomía del órgano, "información ya publicada/accesible", carácter no vinculante de los informes, reiteración de la causa de inadmisión o del límite, etc.). No te dejes ninguna.
3. Responde al usuario (action = `reply`, SIN `draft`) así: en `conversational_reply` una introducción BREVE (p. ej. "He leído las alegaciones de la Administración. Estas son y así las rebatiré:") y, al final, la pregunta de confirmación ("¿Te parece bien este enfoque o quieres que ajuste algo antes de redactar?"). El detalle va en el campo `plan`: un elemento por cada alegación, con `argument` (qué alega la Administración) y `strategy` (cómo la rebates). El cliente mostrará cada elemento como una tarjeta, así que NO repitas el listado dentro de `conversational_reply`.
4. En FASE 1 **NO llames a `search_resolutions`** todavía y **NO escribas el borrador**. Solo lee las alegaciones, identifícalas y propón el plan de réplica.

**FASE 2 — Redactar la respuesta (action = `generate`).**
SOLO cuando, en un turno ANTERIOR, ya hayas hecho la FASE 1 y el usuario haya confirmado el plan (p. ej. "ok", "adelante", "perfecto", "genera", o proponiéndote ajustes concretos): para cada alegación confirmada busca con `search_resolutions` resoluciones que la contradigan (y `search_criteria` si la alegación reitera una causa de inadmisión o un límite con doctrina interpretativa), y emite el escrito completo de RESPUESTA rebatiendo las alegaciones punto por punto.
TXT;

        $twoPhase = $isAlegation ? $twoPhaseAlegation : $twoPhaseComplaint;

        $policy = <<<TXT
# IMPORTANTE: protocolo de salida del chat asistente

Esta conversación se entrega al usuario en una interfaz de chat con canvas. **DEBES OBEDECER el siguiente protocolo de salida**, que SUSTITUYE cualquier instrucción posterior sobre el formato de respuesta:

## Política de decisión

En cada turno decides UNA de tres acciones:

1. `reply` — el usuario pregunta, falta contexto clave, o estás en la FASE 1 de planificación (ver abajo). **Es tu acción por defecto.**
2. `generate` — emitir el borrador por primera vez. **SOLO es válido si en el HISTORIAL de la conversación TÚ YA propusiste un plan de argumentos (FASE 1) y el usuario lo aprobó DESPUÉS.** No hay ninguna otra forma de llegar a `generate`.
3. `rewrite` — ya hay borrador y el usuario pide un cambio concreto.

Estado actual: {$state}

### REGLA DURA (léela dos veces)

**Si NO hay borrador en el canvas, tu acción es SIEMPRE `reply` con un plan (FASE 1). Está PROHIBIDO usar `generate` en tu primera respuesta a una petición de redactar.**

Aunque el usuario escriba "Redacta la reclamación", "Genera el escrito", "Hazme la reclamación", "Prepara la reclamación", "redacta ya", "directamente", "sin preguntarme" o cualquier variante, eso **INICIA el proceso** — y el proceso SIEMPRE empieza por la FASE 1. **NO equivale a "genera ya" y NO te autoriza a saltarte la FASE 1.** La FASE 1 (mostrar el plan y esperar la aprobación) es OBLIGATORIA y NO se puede omitir bajo ninguna circunstancia: antes de generar TIENES que haber mostrado el plan en un turno anterior y el usuario tiene que haberlo aprobado. Si no ves esa aprobación explícita en el historial, haz FASE 1.

{$twoPhase}

**La FASE 1 NO se puede saltar.** No existe ninguna excepción: aunque el usuario insista en que redactes directamente o sin confirmación, primero muestras el plan (FASE 1) y esperas su aprobación. Puedes generar en el MISMO turno solo si el plan ya estaba aprobado en el historial.

**Si ya hay un borrador en el canvas**, NO repitas la FASE 1: trata la petición como `rewrite` o `reply` según corresponda.

## Formato de salida (OBLIGATORIO)

Responde ÚNICAMENTE con un bloque JSON válido (sin code fences) con esta forma:

{
  "conversational_reply": "respuesta breve al usuario en español, en HTML directo (NO Markdown). Etiquetas permitidas: <p>, <strong>, <em>, <ul>, <ol>, <li>, <br>, <a>, <code>. En FASE 1: una introducción breve y la pregunta de confirmación; el listado de argumentos NO va aquí, va en `plan`. NO menciones que sigues un protocolo.",
  "action": "reply" | "generate" | "rewrite",
  "plan": [{ "argument": "qué alega la Administración (1 frase)", "strategy": "cómo lo desmontas (1-2 frases)" }],
  "draft": {
    "title": "Asunto breve (≤255)",
    "body_html": "HTML completo de {$verb}, siguiendo las pautas del scaffolding."
  }
}

Reglas estrictas:
- FASE 1 (action == "reply" con plan): rellena `plan` con un elemento por argumento y **OMITE** `draft`.
- Si action == "generate" o "rewrite": deja `plan` vacío/null y "body_html" contiene {$verb} ENTERA en HTML. Devuelve el documento completo, NO un parche.
- Si action == "reply" sin plan (una simple pregunta/aclaración): omite tanto `plan` como `draft`.
- "title" puede ser breve y descriptivo del escrito.
- SOLO el JSON, sin texto fuera de él.
- Las instrucciones posteriores dicen cómo redactar el HTML; este protocolo describe cómo entregarlo.

TXT;

        $prefsText = WritingPreferencesFormatter::format($ar->getUser()?->getWritingPreferences() ?? []);
        $prefsBlock = $prefsText !== '' ? "\n\n" . $prefsText : '';

        // Literal text of the applicable transparency law and of the regime attached to the
        // capacity the user acts in. Same rationale as in RequestPromptComposer: injected in
        // PHP so a Langfuse edit can never silently drop it.
        $legalText = $this->legalFramework->compose($ar);
        $legalBlock = $legalText !== '' ? "\n\n" . $legalText : '';

        $borradorBlock = $hasDraft
            ? "\n\n## Borrador actual en el canvas (PUNTO DE PARTIDA para `rewrite`)\n\n{$currentBodyHtml}\n"
            : '';

        $fullText = $policy . $prefsBlock . $legalBlock . $borradorBlock . "\n\n## Scaffolding del flujo de reclamaciones\n\n" . $scaffolding->text;

        return new CompiledPrompt(text: $fullText, name: $scaffolding->name, version: $scaffolding->version);
    }
}
