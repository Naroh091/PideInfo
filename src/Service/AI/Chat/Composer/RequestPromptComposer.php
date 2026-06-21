<?php

declare(strict_types=1);

namespace App\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
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
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     */
    public function compose(AccessRequest $ar, array $similarResolutions): string
    {
        $isReg = $ar->getRegDestination() !== null;
        $law = $ar->getApplicableLaw();
        $deadline = $law !== null ? $this->applicableLawResolver->deadlineLabel($law) : '1 mes (silencio negativo)';

        $sections = [
            'Eres el asistente que ayuda a redactar **solicitudes de acceso a información pública** en España. Hablas con el ciudadano que va a presentar la solicitud. Tu objetivo es llegar a un borrador útil, claro, conciso y bien fundamentado en la ley aplicable.',
            $this->decisionPolicy($isReg, $ar),
            'Organismo destinatario: ' . $ar->getPublicBody()->getName(),
            'Ley aplicable: ' . ($law?->getName() ?? 'Ley 19/2013') . ' (' . ($law?->getShortCode() ?? 'LTAIPBG') . ')',
            'Plazo de respuesta: ' . $deadline,
            $isReg ? $this->regChannelBlock($ar) : $this->portalChannelBlock($ar),
            "Resoluciones similares (para inspirarte sin copiar literalmente):\n" . $this->formatResolutions($similarResolutions),
        ];

        $user = $ar->getUser();
        if ($user->hasWritingPreferences()) {
            $sections[] = $this->formatPreferences($user->getWritingPreferences());
        }

        return implode("\n\n", $sections);
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

Escribe primero la respuesta conversacional para el usuario, en español, natural, sin metalenguaje ("voy a…", "como modelo de IA…"), tan breve como puedas ser útil.

A continuación, en una línea aparte, escribe literalmente la marca:

===DECISION===

Y debajo, un único bloque JSON válido (sin code fences) con la forma:

{
  "action": "reply" | "generate" | "rewrite",
  {$draftShape}  // OMÍTELO si action == "reply"
}

Reglas:
- Si action == "reply", NO incluyas la clave "draft".
- Si action == "generate" o "rewrite", incluye "draft" COMPLETO con todos sus campos (no devuelvas parches).
- No añadas más texto después del JSON.
- No uses comillas tipográficas; respeta los límites de longitud.
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

    /** @param array{tone?: string, salutation?: string, closing?: string, notes?: string} $prefs */
    private function formatPreferences(array $prefs): string
    {
        $lines = [];
        $labels = ['tone' => 'Tono', 'salutation' => 'Saludo/encabezado', 'closing' => 'Cierre', 'notes' => 'Instrucciones adicionales'];
        foreach ($labels as $key => $label) {
            if (!empty($prefs[$key])) {
                $lines[] = "- **{$label}:** {$prefs[$key]}";
            }
        }
        return "## Preferencias de redacción del usuario\n\nAplica estas preferencias al redactar:\n\n" . implode("\n", $lines);
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
