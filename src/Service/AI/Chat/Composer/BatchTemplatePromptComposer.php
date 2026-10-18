<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AccessRequest\Batch\BatchPlaceholders;
use App\Service\AccessRequest\Batch\DraftBatchTemplate;
use App\Service\AI\Chat\WritingPreferencesFormatter;

/**
 * System prompt for the unified-template chat of a multi-recipient batch
 * ("plantilla común"). Same split as {@see RequestPromptComposer}: the editable
 * policy and drafting guidance live in the managed prompt
 * `pideinfo-request-generate-batch-template-chat`; the machine output contract —
 * coupled to the parser — stays in PHP.
 *
 * Unlike the per-request prompt it injects NO literal law text: recipients may
 * be governed by different laws, so the template invokes `[[LEY_APLICABLE]]`
 * and concrete articles are only added per version, from that version's own
 * legal framework ({@see \App\Service\AccessRequest\Batch\BatchVersionGenerator}).
 */
final class BatchTemplatePromptComposer
{
    public const PROMPT_NAME = 'pideinfo-request-generate-batch-template-chat';

    public function __construct(
        private readonly PromptStore $promptStore,
    ) {
    }

    /**
     * @param list<AccessRequest> $siblings
     */
    public function compose(DraftBatchTemplate $template, array $siblings): CompiledPrompt
    {
        $scaffolding = $this->promptStore->compile(self::PROMPT_NAME, [
            'recipients' => $this->recipientsBlock($siblings),
            'placeholders' => $this->placeholdersBlock(),
            'state' => $template->isEmpty()
                ? 'La plantilla aún NO existe.'
                : "YA EXISTE una plantilla:\nASUNTO: {$template->title}\nEXPONE:\n{$template->expone}\nSOLICITA:\n{$template->solicita}",
        ]);

        $fullText = $scaffolding->text;

        $owner = $siblings !== [] ? $siblings[0]->getUser() : null;
        $prefsBlock = WritingPreferencesFormatter::format($owner?->getWritingPreferences() ?? []);
        if ($prefsBlock !== '') {
            $fullText .= "\n\n" . $prefsBlock;
        }

        $fullText .= "\n\n" . $this->outputContract();

        return new CompiledPrompt(
            text: $fullText,
            name: $scaffolding->name,
            version: $scaffolding->version,
        );
    }

    /**
     * @param list<AccessRequest> $siblings
     */
    private function recipientsBlock(array $siblings): string
    {
        $lines = [];
        foreach ($siblings as $ar) {
            $values = BatchPlaceholders::valuesFor($ar);
            $channel = $ar->getRegDestination() !== null ? 'registro electrónico (REG)' : 'Portal de Transparencia';
            $lines[] = sprintf(
                '- %s — unidad: %s — ley: %s — canal: %s',
                $values[BatchPlaceholders::ORGANISMO],
                $values[BatchPlaceholders::UNIDAD],
                $values[BatchPlaceholders::LEY_APLICABLE] !== '' ? $values[BatchPlaceholders::LEY_APLICABLE] : 'por determinar',
                $channel,
            );
        }

        return implode("\n", $lines);
    }

    private function placeholdersBlock(): string
    {
        $lines = [];
        foreach (BatchPlaceholders::catalog() as $name => $meaning) {
            $lines[] = sprintf('- `%s` — %s', BatchPlaceholders::marker($name), $meaning);
        }

        return implode("\n", $lines);
    }

    /**
     * Kept in PHP for the same reason as RequestPromptComposer::outputContract():
     * the streaming parser and the template store expect exactly this shape. The
     * template is always asunto + EXPONE + SOLICITA; the asunto is capped at the
     * REG limit so it fits every channel.
     */
    private function outputContract(): string
    {
        return <<<'TXT'
## Formato de salida (OBLIGATORIO)

Responde ÚNICAMENTE con un bloque JSON válido (sin code fences) con esta forma:

{
  "conversational_reply": "respuesta al usuario en español, natural y breve, en HTML directo (NO Markdown)",
  "action": "reply" | "generate" | "rewrite",
  "draft": {"title": str ≤80, "expone": str ≤4000, "solicita": str ≤4000, "sources": [{"type": "resolution"|"criterion"|"judgment", "reference": str, "label": str}]}  // OMÍTELO si action == "reply"
}

Reglas:
- `conversational_reply`: tu respuesta directa al usuario, en **HTML directo, NO Markdown**. Etiquetas permitidas: <p>, <strong>, <em>, <ul>, <ol>, <li>, <br>, <a>, <code>. Cuando generes o reescribas la plantilla, recuerda en una frase que el siguiente paso es generar las versiones por organismo.
- Si action == "reply", NO incluyas la clave "draft".
- Si action == "generate" o "rewrite", incluye "draft" COMPLETO con todos sus campos (no devuelvas parches). Los marcadores [[...]] van tal cual dentro del texto.
- `sources`: SOLO resoluciones, criterios o sentencias leídos con las tools en ESTA conversación y citados en el texto, con su `reference` EXACTA. Lista vacía si no citas ninguna. NUNCA inventes referencias.
- SOLO el JSON, sin texto fuera de él.
TXT;
    }
}
