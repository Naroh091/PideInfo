<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Chat\WritingPreferencesFormatter;
use App\Service\AI\ResolutionRetriever;
use App\Service\Submission\ApplicableLawResolver;

/**
 * Composes the system prompt for the access-request flow of the unified chat
 * assistant. Encodes the decision policy ({@see PromptDecisionPolicy}) and
 * the channel-specific draft shape (REG → expone/solicita; AGE/email →
 * single description body).
 */
final class RequestPromptComposer
{
    public function __construct(
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly PromptStore $promptStore,
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

        $scaffolding = $this->promptStore->compile('pideinfo-request-generate-request-chat', [
            'organism' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $law->getName(),
            'applicable_law_code' => $law->getShortCode(),
            'deadline' => $deadline,
            'channel_block' => $isReg ? $this->regChannelBlock($ar) : $this->portalChannelBlock($ar),
            'similar_resolutions' => $this->formatResolutions($similarResolutions),
        ]);

        $fullText = $this->decisionPolicy($isReg, $ar) . "\n\n" . $scaffolding->text;

        $prefsBlock = WritingPreferencesFormatter::format($ar->getUser()->getWritingPreferences());
        if ($prefsBlock !== '') {
            $fullText .= "\n\n" . $prefsBlock;
        }

        return new CompiledPrompt(
            text: $fullText,
            name: $scaffolding->name,
            version: $scaffolding->version,
        );
    }

    private function decisionPolicy(bool $isReg, AccessRequest $ar): string
    {
        $hasDraft = $isReg
            ? (trim((string) $ar->getExpone()) !== '' || trim((string) $ar->getSolicita()) !== '')
            : trim((string) $ar->getDescription()) !== '';

        $draftShape = $isReg
            ? '"draft": {"title": str ≤80, "expone": str ≤4000, "solicita": str ≤4000}'
            : '"draft": {"title": str ≤255, "body_text": str ≤3000}';

        $state = $hasDraft ? 'YA EXISTE un borrador en el canvas.' : 'El borrador aún NO existe.';

        return <<<TXT
## Política de decisión

En cada turno decides UNA de tres acciones:

1. `reply` — el usuario pregunta, falta contexto clave, o aún no se ha llegado a un consenso sobre qué información pedir.
2. `generate` — el borrador está vacío y el contexto acumulado es suficiente para un primer redactado.
3. `rewrite` — ya hay borrador y el usuario pide un cambio concreto (tono, longitud, párrafos, foco…).

Estado actual: {$state}

## Formato de salida (OBLIGATORIO)

Responde ÚNICAMENTE con un bloque JSON válido (sin code fences) con esta forma:

{
  "conversational_reply": "respuesta al usuario en español, natural, sin metalenguaje, tan breve como puedas ser útil",
  "action": "reply" | "generate" | "rewrite",
  {$draftShape}  // OMÍTELO si action == "reply"
}

Reglas:
- `conversational_reply`: tu respuesta directa al usuario. NO menciones que vas a incluir JSON ni que sigues un protocolo.
- Si action == "reply", NO incluyas la clave "draft".
- Si action == "generate" o "rewrite", incluye "draft" COMPLETO con todos sus campos (no devuelvas parches).
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
