<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\AI\Chat\WritingPreferencesFormatter;
use App\Service\AI\ResolutionRetriever;
use App\Service\Submission\ApplicableLawResolver;

/**
 * Composes the system prompt for the access-request flow of the unified chat
 * assistant. The behavioural decision policy (`reply`/`generate`/`rewrite`)
 * lives in the managed prompt `pideinfo-request-generate-request-chat` (tunable
 * in Langfuse, `{{state}}` injected here); only the machine output contract —
 * the JSON shape coupled to the parser — and the channel-specific draft shape
 * (REG → expone/solicita; AGE/email → single description body) stay in PHP
 * ({@see self::outputContract()}).
 */
final class RequestPromptComposer
{
    public function __construct(
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly PromptStore $promptStore,
        private readonly LegalFrameworkComposer $legalFramework,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     */
    public function compose(AccessRequest $ar, array $similarResolutions): CompiledPrompt
    {
        $isReg = $ar->getRegDestination() !== null;
        $law = $ar->getApplicableLaw();
        $deadline = $this->applicableLawResolver->deadlineLabel($law);

        $hasDraft = $isReg
            ? (trim((string) $ar->getExpone()) !== '' || trim((string) $ar->getSolicita()) !== '')
            : trim((string) $ar->getDescription()) !== '';

        $scaffolding = $this->promptStore->compile('pideinfo-request-generate-request-chat', [
            'organism' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $law->getName(),
            'applicable_law_code' => $law->getShortCode(),
            'deadline' => $deadline,
            'channel_block' => $isReg ? $this->regChannelBlock($ar) : $this->portalChannelBlock($ar),
            'similar_resolutions' => $this->formatResolutions($similarResolutions),
            // The decision policy (`reply`/`generate`/`rewrite`) now lives inside the
            // managed prompt so it can be tuned in Langfuse without a redeploy; `state`
            // is the only dynamic bit it needs. Only the machine output contract —
            // coupled to the parser — stays in PHP (see outputContract()).
            'state' => $hasDraft ? 'YA EXISTE un borrador en el canvas.' : 'El borrador aún NO existe.',
        ]);

        $fullText = $scaffolding->text;

        // The literal text of the applicable law (and of the regime that governs the capacity
        // the user acts in — a concejal is not governed by the Ley 19/2013). Injected here in
        // PHP, next to the writing preferences, and not as a template variable: the prompts are
        // served by Langfuse, and a version edited there without the placeholder would drop the
        // whole block without a trace.
        $legalBlock = $this->legalFramework->compose($ar);
        if ($legalBlock !== '') {
            $fullText .= "\n\n" . $legalBlock;
        }

        $prefsBlock = WritingPreferencesFormatter::format($ar->getUser()?->getWritingPreferences() ?? []);
        if ($prefsBlock !== '') {
            $fullText .= "\n\n" . $prefsBlock;
        }

        // The output contract goes LAST: it is the machine-facing instruction the
        // model must obey verbatim (JSON shape, field names, length caps), and the
        // parser depends on it — keeping it out of Langfuse prevents a silent break.
        $fullText .= "\n\n" . $this->outputContract($isReg);

        return new CompiledPrompt(
            text: $fullText,
            name: $scaffolding->name,
            version: $scaffolding->version,
        );
    }

    /**
     * The machine output contract. Kept in PHP (not in the Langfuse-managed
     * prompt) because it is coupled to the parser: {@see StreamingDecisionSplitter}
     * and the draft normalization expect exactly this JSON shape and field names,
     * so a stray edit in Langfuse would break parsing silently. The behavioural
     * decision policy (`reply`/`generate`/`rewrite`), by contrast, lives in the
     * managed prompt so it can be tuned without a redeploy.
     */
    private function outputContract(bool $isReg): string
    {
        $sourcesShape = '"sources": [{"type": "resolution"|"criterion"|"judgment", "reference": str, "label": str}]';
        $draftShape = $isReg
            ? '"draft": {"title": str ≤80, "expone": str ≤4000, "solicita": str ≤4000, ' . $sourcesShape . '}'
            : '"draft": {"title": str ≤255, "body_text": str ≤3000, ' . $sourcesShape . '}';

        return <<<TXT
## Formato de salida (OBLIGATORIO)

Responde ÚNICAMENTE con un bloque JSON válido (sin code fences) con esta forma:

{
  "conversational_reply": "respuesta al usuario en español, natural y breve, en HTML directo (NO Markdown)",
  "action": "reply" | "generate" | "rewrite",
  {$draftShape}  // OMÍTELO si action == "reply"
}

Reglas:
- `conversational_reply`: tu respuesta directa al usuario, en **HTML directo, NO Markdown** (nada de `**negrita**` ni `#` — el cliente NO renderiza Markdown y se verían los símbolos). Etiquetas permitidas: <p>, <strong>, <em>, <ul>, <ol>, <li>, <br>, <a>, <code>. NO menciones que vas a incluir JSON ni que sigues un protocolo.
- Si action == "reply", NO incluyas la clave "draft".
- Si action == "generate" o "rewrite", incluye "draft" COMPLETO con todos sus campos (no devuelvas parches).
- `sources`: incluye SOLO las resoluciones, criterios (CI) o sentencias que hayas leído con las tools (search_resolutions / search_criteria / search_judgments) en ESTA conversación y que EFECTIVAMENTE cites en el texto, con su `reference` EXACTA tal como aparece en el resultado de la tool. Deja la lista vacía si no citas ninguna. NUNCA inventes referencias ni enlaces.
- No uses comillas tipográficas; respeta los límites de longitud.
- SOLO el JSON, sin texto fuera de él.
TXT;
    }

    private function regChannelBlock(AccessRequest $ar): string
    {
        $destination = $ar->getRegDestination();
        $unit = $destination?->getDisplayLabel() ?? 'Unidad por determinar';

        $current = "Borrador actual:\nTÍTULO: " . $ar->getTitle()
            . "\nEXPONE:\n" . ($ar->getExpone() ?? '(vacío)')
            . "\nSOLICITA:\n" . ($ar->getSolicita() ?? '(vacío)');

        return <<<TXT
## Canal: Registro Electrónico Común (REG / RED SARA)

La solicitud se presentará vía REG. El formulario tiene un campo de asunto y DOS campos diferenciados para el cuerpo:
- **ASUNTO**: máximo estricto de **80 caracteres**. Debe ser muy breve y descriptivo (el portal trunca silenciosamente lo que sobre).
- **EXPONE**: hechos, contexto, motivación. Texto plano, sin HTML. Máx. 4000 caracteres.
- **SOLICITA**: petición concreta amparada en la ley aplicable. Texto plano, sin HTML. Máx. 4000 caracteres.

Unidad receptora (DIR3): {$unit}.

{$current}
TXT;
    }

    private function portalChannelBlock(AccessRequest $ar): string
    {
        $current = "Borrador actual:\nTÍTULO: " . $ar->getTitle()
            . "\nCUERPO:\n" . ($ar->getDescription() ?? '(vacío)');

        return <<<TXT
## Canal: Portal de Transparencia / correo

La solicitud va por el Portal de Transparencia (cuando hay) o por correo electrónico. Devuelves un cuerpo único en texto plano, máximo 3000 caracteres.

{$current}
TXT;
    }

    /**
     * @param array<int, array<string, mixed>> $resolutions
     */
    private function formatResolutions(array $resolutions): string
    {
        if ($resolutions === []) {
            return 'No se han encontrado resoluciones suficientemente análogas en el corpus.';
        }
        return $this->resolutionRetriever->formatForPrompt($resolutions);
    }
}
