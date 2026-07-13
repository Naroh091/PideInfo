<?php

declare(strict_types=1);

namespace App\Service\Resolution;

use App\DTO\ChatMessage;
use App\Entity\Resolution;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;

/**
 * When the analysis contradicts ITSELF, hand the contradiction back to the model.
 *
 * R/0701/2018 (BOSCO) is the case that exposed it: the same pass labelled the outcome `favorable`
 * while writing, in its own summary, «el Consejo estima parcialmente la reclamación, denegando solo
 * el código fuente». The label came from the fallo's opening «ESTIMAR la reclamación» and missed the
 * carve-out that followed — and it overwrote the `partial` the CTBG listing had got right.
 *
 * The tempting fix is a regex that overrules the model. That is worse than it looks: a regex has
 * not read the resolution, and the corpus is full of «la Administración estimó parcialmente la
 * SOLICITUD», which is often precisely why the Council then DISMISSES the claim. So the regex only
 * ever plays DETECTOR ({@see ResolutionAnalyzer::summarySaysPartial()}); the one that decides is the
 * model, in a second turn, with its own answer quoted back at it and the literal FALLO in front of
 * it — the part it demonstrably did not weigh the first time.
 *
 * It fires only on the contradiction: 23 rows in a 45,891-resolution corpus. The cost is noise.
 */
final class OutcomeReconciler
{
    /**
     * The fallo lives at the END of a resolution ("RESUELVE / En atención a los Antecedentes…").
     * Sending the head would repeat the mistake we are trying to fix.
     */
    private const DISPOSITIVO_CHARS = 6_000;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $storedLabel the outcome the first pass produced
     * @param string $reason      'self' (its own summary says partial) or 'source' (the council's
     *                            listing said partial and the model demoted it). The challenge is
     *                            written differently for each: confronting the model with the wrong
     *                            evidence would just invite it to defend itself.
     *
     * @return array{outcome: string, reasoning: string}|null null when the model cannot be reached
     *                                                        or answers outside the vocabulary —
     *                                                        the caller then keeps what it had and
     *                                                        flags the row, rather than guessing
     */
    public function reconcile(Resolution $resolution, string $storedLabel, string $reason = 'self'): ?array
    {
        $fullText = $resolution->getFullText();
        if (trim($fullText) === '') {
            return null;
        }

        try {
            $prompt = $this->promptStore->compile('pideinfo-resolution-outcome-tiebreak');

            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                // A genuine second turn: the model's own answer is put back in its mouth, and then
                // it is confronted with it. Cheaper and sharper than re-analysing from scratch.
                messages: [
                    new ChatMessage(role: 'model', content: json_encode([
                        'outcome' => $storedLabel,
                        'summary' => $resolution->getSummary(),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                ],
                userText: $this->challenge($resolution, $storedLabel, $reason),
                requiredJsonKeys: ['outcome', 'reasoning'],
                maxOutputTokens: 1024,
                label: 'OutcomeTiebreak',
                traceName: 'OutcomeTiebreak',
            ));

            $outcome = is_string($result['outcome'] ?? null) ? strtolower(trim($result['outcome'])) : '';

            if (!array_key_exists($outcome, Resolution::getOutcomeLabels())) {
                $this->logger->warning('Outcome tie-break returned an unknown outcome', [
                    'resolution' => $resolution->getReferenceNumber(),
                    'outcome' => $outcome,
                ]);

                return null;
            }

            return [
                'outcome' => $outcome,
                'reasoning' => is_string($result['reasoning'] ?? null) ? mb_substr($result['reasoning'], 0, 500) : '',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Outcome tie-break failed', [
                'resolution' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function challenge(Resolution $resolution, string $storedLabel, string $reason): string
    {
        $conflict = $reason === 'source'
            ? <<<TXT
                - La ETIQUETA que has dado es: **{$storedLabel}**
                - Pero el LISTADO OFICIAL del propio consejo publica esta resolución como
                  **estimación parcial**, y tú la has degradado a un resultado total.
                TXT
            : <<<TXT
                - La ETIQUETA que has dado es: **{$storedLabel}**
                - Pero tu propio RESUMEN dice que el Consejo estimó (o desestimó) PARCIALMENTE la
                  reclamación.
                TXT;

        return <<<TXT
            Hay una contradicción en tu análisis:

            {$conflict}

            Las dos cosas no pueden ser ciertas a la vez. Lee el FALLO literal de la resolución —
            está abajo, y es lo único que decide — y resuélvela. Si el fallo te da la razón,
            mantén tu etiqueta.

            Ojo con la trampa: que la ADMINISTRACIÓN atendiera parcialmente la SOLICITUD no convierte
            la resolución en parcial. Lo que cuenta es qué hizo el CONSEJO con la RECLAMACIÓN.

            === FALLO Y PARTE FINAL DE LA RESOLUCIÓN {$resolution->getReferenceNumber()} ===
            {$this->dispositivo($resolution)}
            TXT;
    }

    private function dispositivo(Resolution $resolution): string
    {
        $text = $resolution->getFullText();

        return mb_strlen($text) <= self::DISPOSITIVO_CHARS
            ? $text
            : "[…parte inicial omitida…]\n\n" . mb_substr($text, -self::DISPOSITIVO_CHARS);
    }
}
