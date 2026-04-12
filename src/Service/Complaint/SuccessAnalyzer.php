<?php

namespace App\Service\Complaint;

use App\DTO\SuccessAnalysis;
use App\Entity\AccessRequest;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\CustomModelClient;
use App\Service\AI\ResolutionRetriever;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SuccessAnalyzer
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
        #[Autowire(env: 'GEMINI_MID_MODEL')]
        private readonly string $geminiModel,
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly CustomModelClient $customModelClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function analyze(AccessRequest $accessRequest): SuccessAnalysis
    {
        $contextQuery = $this->buildContextQuery($accessRequest);
        $criteria = $this->criteriaRetriever->retrieve($contextQuery, 3);
        $favorablePrecedents = $this->resolutionRetriever->retrieveSimilarCases(
            $contextQuery,
            3,
            ['favorable', 'partial', 'acuerdo_mediacion'],
        );
        $unfavorablePrecedents = $this->resolutionRetriever->retrieveSimilarCases(
            $contextQuery,
            3,
            ['unfavorable', 'inadmissible'],
        );

        $prompt = $this->buildAnalysisPrompt($accessRequest, $criteria, $favorablePrecedents, $unfavorablePrecedents);
        $schema = $this->buildResponseSchema();

        try {
            $result = $this->customModelClient->isEnabled()
                ? $this->customModelClient->chat($prompt, jsonSchema: $schema, temperature: 0.1, schemaName: 'success_analysis')
                : $this->callGeminiApi($prompt, $schema);
            return $this->parseAnalysisResult($result);
        } catch (\Exception $e) {
            $this->logger->warning('Success analyzer failed', [
                'request' => (string) $accessRequest->getId(),
                'error' => $e->getMessage(),
            ]);
            return new SuccessAnalysis(
                probability: 50,
                reasoning: 'No se pudo analizar la probabilidad de éxito debido a un error técnico.',
                strengths: [],
                weaknesses: [],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'probability' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Probabilidad estimada (0-100) de que una reclamación sobre esta solicitud sea estimada por el consejo de transparencia competente.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Explicación breve (1-2 frases) del razonamiento que sustenta la probabilidad.',
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'De 2 a 4 puntos concretos a favor del reclamante (precedentes favorables, criterios aplicables, ausencia de límites claros).',
                ],
                'weaknesses' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'De 2 a 4 riesgos o puntos en contra (precedentes desfavorables, posibles causas de inadmisión, límites aplicables).',
                ],
            ],
            'required' => ['probability', 'reasoning', 'strengths', 'weaknesses'],
        ];
    }

    private function buildContextQuery(AccessRequest $accessRequest): string
    {
        $parts = [
            $accessRequest->getTitle(),
            $accessRequest->getDescription(),
        ];

        if ($accessRequest->getResolutionNotes()) {
            $parts[] = 'Motivo de denegación: ' . $accessRequest->getResolutionNotes();
        }

        return implode('. ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<int, array<string, mixed>> $favorablePrecedents
     * @param array<int, array<string, mixed>> $unfavorablePrecedents
     */
    private function buildAnalysisPrompt(
        AccessRequest $accessRequest,
        array $criteria,
        array $favorablePrecedents,
        array $unfavorablePrecedents,
    ): string {
        $status = match (true) {
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'silencio administrativo negativo',
            $accessRequest->isDeadlinePassed() => 'silencio administrativo negativo (plazo vencido)',
            default => 'pendiente',
        };

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No indicado';
        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $favorableText = $this->resolutionRetriever->formatForPrompt($favorablePrecedents);
        $unfavorableText = empty($unfavorablePrecedents)
            ? 'No se encontraron precedentes desfavorables análogos. Esto no significa que no existan — puede indicar simplemente falta de cobertura en el índice.'
            : $this->resolutionRetriever->formatForPrompt($unfavorablePrecedents);

        return <<<PROMPT
Actúa como un abogado especialista en derecho de acceso a información pública en España. Debes analizar la probabilidad de éxito de una eventual reclamación al consejo de transparencia competente.

## DATOS DE LA SOLICITUD

**Información solicitada:** {$accessRequest->getTitle()}

**Descripción:** {$accessRequest->getDescription()}

**Organismo reclamado:** {$accessRequest->getPublicBody()->getName()}

**Estado de la solicitud:** {$status}

**Motivo de denegación alegado por la administración:** {$denialReason}

## CRITERIOS INTERPRETATIVOS RECUPERADOS

{$criteriaText}

## PRECEDENTES FAVORABLES ENCONTRADOS (estimaciones y estimaciones parciales)

{$favorableText}

## PRECEDENTES DESFAVORABLES ENCONTRADOS (desestimaciones y inadmisiones)

{$unfavorableText}

## INSTRUCCIONES

Tu tarea es producir una estimación REALISTA de la probabilidad de que la reclamación prospere. Debes ponderar ambos lados:

1. **Lee primero el resumen y los puntos clave de cada precedente** para juzgar si son GENUINAMENTE análogos al caso actual. La búsqueda vectorial devuelve candidatos por similitud semántica, no por aplicabilidad jurídica — muchos no serán pertinentes. Descarta mentalmente los que no se ajusten al fondo.
2. **Los precedentes favorables SOLO cuentan como ventaja si son realmente análogos**; si son superficialmente similares pero versan sobre supuestos distintos, ignóralos.
3. **Los precedentes desfavorables son igual de importantes que los favorables**: si encuentras casos análogos donde el consejo rechazó, eso debe BAJAR la probabilidad significativamente aunque haya precedentes favorables también.
4. **No te dejes deslumbrar por el número de precedentes**: la calidad (aplicabilidad al caso) importa más que la cantidad.
5. Considera además:
   - El tipo de información solicitada y si encaja con los límites del art. 14 de la Ley 19/2013 (secretos, datos personales, etc.).
   - Si la solicitud podría encajar en alguna causa de inadmisión del art. 18 (información auxiliar, reelaboración, no competencia, etc.).
   - El motivo de denegación alegado y su solidez jurídica.
   - Si hay silencio administrativo, recuerda que la obligación de resolver expresa siempre está presente.
6. **El valor numérico de `probability` debe reflejar la INCERTIDUMBRE real**: usa el rango 0-100 con cabeza. 50% significa "podría ir en cualquier dirección". 90% significa "prácticamente seguro que se estima". Reserva los extremos (>80 o <20) para casos donde la jurisprudencia sea clara.

En `strengths` incluye 2-4 puntos concretos a favor del reclamante (precedentes favorables genuinos, criterios aplicables, ausencia de límites defendibles).

En `weaknesses` incluye 2-4 riesgos o puntos en contra (precedentes desfavorables análogos, posibles causas de inadmisión, límites que la administración podría invocar con éxito).

NO inventes referencias a resoluciones o criterios que no estén en el contexto proporcionado. Si no hay evidencia suficiente, refléjalo en la probabilidad y el razonamiento.
PROMPT;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function callGeminiApi(string $prompt, array $schema): string
    {
        $url = sprintf(self::GEMINI_ENDPOINT, $this->geminiModel) . '?key=' . $this->geminiApiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 2048,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini API error: ' . $response);
        }

        $data = json_decode($response, true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function parseAnalysisResult(string $result): SuccessAnalysis
    {
        $result = preg_replace('/^```json\s*/', '', $result);
        $result = preg_replace('/\s*```$/', '', $result);
        $result = trim($result);

        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse analysis result as JSON: ' . $result);
        }

        return SuccessAnalysis::fromArray($data);
    }
}
