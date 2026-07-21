<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\AI\Chat\WritingPreferencesFormatter;

/**
 * Composes the system prompt for the free-consultation flow of the unified chat
 * assistant (`flow=consult`): a general assistant that answers doubts about an
 * expediente AND generates any outbound document inline (reclamación, respuesta
 * a alegaciones, respuesta a subsanación, or a custom user-described writing),
 * classifying it into a `doc_type`.
 *
 * Same split as the other composers: the behavioural scaffold lives in the
 * managed prompt `pideinfo-consult-generate-doc-chat` (tunable in Langfuse,
 * `{{state}}` injected here); the legal framework + writing preferences are
 * PHP-injected; and the machine output contract — the JSON shape coupled to the
 * parser, incl. the `doc_type` classification — stays in PHP ({@see self::outputContract()}).
 * No FASE-1 two-phase planning (that is complaint-flow only): consult answers
 * and drafts directly.
 */
final class ConsultPromptComposer
{
    public function __construct(
        private readonly PromptStore $promptStore,
        private readonly LegalFrameworkComposer $legalFramework,
    ) {
    }

    public function compose(AccessRequest $ar, string $currentBodyHtml = ''): CompiledPrompt
    {
        $law = $ar->getApplicableLaw();
        $hasDoc = trim(strip_tags($currentBodyHtml)) !== '';

        $scaffolding = $this->promptStore->compile('pideinfo-consult-generate-doc-chat', [
            'organism' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $law?->getName() ?? 'la ley de transparencia aplicable',
            'applicable_law_code' => $law?->getShortCode() ?? '',
            'request_context' => $this->requestContext($ar),
            'state' => $hasDoc
                ? 'YA HAY un documento en el lienzo (puedes reescribirlo).'
                : 'Aún NO hay ningún documento en el lienzo.',
        ]);

        $fullText = $scaffolding->text;

        // Literal statute text (and the regime of the capacity the user acts in).
        // PHP-injected for the same reason as the other flows: a Langfuse edit
        // that drops the placeholder must not silently drop the law.
        $legalBlock = $this->legalFramework->compose($ar);
        if ($legalBlock !== '') {
            $fullText .= "\n\n" . $legalBlock;
        }

        $prefsBlock = WritingPreferencesFormatter::format($ar->getUser()?->getWritingPreferences() ?? []);
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
     * A short factual block about THIS expediente so the agent writes with real
     * data (asunto, fecha, qué se pidió) and knows which documents exist to read
     * with `read_request_documents` — never leaving placeholders for them.
     */
    private function requestContext(AccessRequest $ar): string
    {
        $lines = [];

        $title = trim($ar->getTitle());
        if ($title !== '') {
            $lines[] = '- **Asunto de la solicitud:** ' . $title;
        }
        $lines[] = '- **Solicitud registrada:** ' . $ar->getCreatedAt()->format('d/m/Y');

        $description = trim($ar->getDescription());
        if ($description !== '') {
            $lines[] = '- **Qué se solicitó (extracto):** ' . mb_substr($description, 0, 700);
        }

        $docLines = [];
        foreach ($ar->getDocuments() as $document) {
            $date = $document->getDocumentDate() ?? $document->getCreatedAt();
            $name = trim((string) ($document->getCustomName() ?? '')) !== ''
                ? $document->getCustomName()
                : $document->getOriginalFilename();
            $docLines[] = sprintf(
                '  - %s%s%s',
                $document->getType()->label(),
                $date ? ' · ' . $date->format('d/m/Y') : '',
                $name ? ' · ' . mb_substr((string) $name, 0, 80) : '',
            );
        }
        if ($docLines !== []) {
            $lines[] = "\nDocumentos ya en el expediente (léelos con `read_request_documents` para los datos exactos):\n" . implode("\n", $docLines);
        }

        return implode("\n", $lines);
    }

    /**
     * The machine output contract. Kept in PHP (not in the Langfuse-managed
     * prompt) because it is coupled to the parser ({@see \App\Service\AI\StreamingDecisionSplitter})
     * and to the `doc_type` classification consumed by the save endpoint.
     */
    private function outputContract(): string
    {
        $sourcesShape = '"sources": [{"type": "resolution"|"criterion"|"judgment", "reference": str, "label": str}]';
        $draftShape = '"draft": {"title": str ≤255, "body_html": str, "doc_type": "complaint"|"alegation_response"|"subsanacion_response"|"other", ' . $sourcesShape . '}';

        return <<<TXT
## Formato de salida (OBLIGATORIO)

Responde ÚNICAMENTE con un bloque JSON válido (sin code fences) con esta forma:

{
  "conversational_reply": "respuesta al usuario, natural y breve, en HTML directo (NO Markdown)",
  "action": "reply" | "generate" | "rewrite",
  {$draftShape}  // OMÍTELO si action == "reply"
}

Reglas:
- `conversational_reply`: tu respuesta directa al usuario, en **HTML directo, NO Markdown** (nada de `**negrita**` ni `#`). Etiquetas permitidas: <p>, <strong>, <em>, <ul>, <ol>, <li>, <br>, <a>, <code>. NO menciones que sigues un protocolo ni que devuelves JSON.
- Si action == "reply", NO incluyas la clave "draft".
- Si action == "generate" o "rewrite", incluye "draft" COMPLETO: `body_html` con el documento entero y `doc_type` con su clasificación (complaint | alegation_response | subsanacion_response | other).
- `sources`: incluye SOLO las resoluciones, criterios (CI) o sentencias que hayas leído con las tools en ESTA conversación y que EFECTIVAMENTE cites, con su `reference` EXACTA. Lista vacía si no citas ninguna. NUNCA inventes referencias.
- No uses comillas tipográficas. SOLO el JSON, sin texto fuera de él.
TXT;
    }
}
