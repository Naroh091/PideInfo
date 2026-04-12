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
        $resolutions = $this->resolutionRetriever->retrieveSimilarCases($contextQuery, 3);

        $prompt = $this->buildAnalysisPrompt($accessRequest, $criteria, $resolutions);

        try {
            $result = $this->customModelClient->isEnabled()
                ? $this->customModelClient->chat($prompt, jsonMode: true, temperature: 0.1)
                : $this->callGeminiApi($prompt);
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

    private function buildAnalysisPrompt(AccessRequest $accessRequest, array $criteria, array $resolutions): string
    {
        $status = match (true) {
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'silencio administrativo negativo',
            $accessRequest->isDeadlinePassed() => 'silencio administrativo negativo (plazo vencido)',
            default => 'pendiente',
        };

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No indicado';

        $criteriaText = !empty($criteria)
            ? implode("\n", array_map(fn($c) => "- {$c['criterion']} ({$c['year']}): {$c['topic']}", $criteria))
            : 'No se encontraron criterios relevantes';

        $resolutionsText = !empty($resolutions)
            ? implode("\n", array_map(fn($r) => "- {$r['reference']}: {$r['outcome']}", $resolutions))
            : 'No se encontraron resoluciones similares';

        return <<<PROMPT
Analiza la probabilidad de éxito de una reclamación ante el Consejo de Transparencia y Buen Gobierno.

## DATOS DE LA SOLICITUD

**Información solicitada:** {$accessRequest->getTitle()}

**Descripción:** {$accessRequest->getDescription()}

**Organismo:** {$accessRequest->getPublicBody()->getName()}

**Estado:** {$status}

**Motivo de denegación:** {$denialReason}

## CRITERIOS INTERPRETATIVOS RELEVANTES

{$criteriaText}

## RESOLUCIONES SIMILARES ENCONTRADAS

{$resolutionsText}

## INSTRUCCIONES

Analiza la probabilidad de que una reclamación sobre esta solicitud sea estimada por el Consejo de Transparencia.

Considera:
1. El tipo de información solicitada
2. El motivo de denegación alegado
3. Los criterios interpretativos del CTBG
4. Resoluciones favorables previas en casos similares
5. Los límites al derecho de acceso (art. 14 y 15 Ley 19/2013)

Responde ÚNICAMENTE con un JSON válido con esta estructura exacta:

{
    "probability": <número entre 0 y 100>,
    "reasoning": "<explicación breve de 1-2 frases>",
    "strengths": ["<punto fuerte 1>", "<punto fuerte 2>"],
    "weaknesses": ["<punto débil 1>", "<punto débil 2>"]
}

No incluyas ningún texto adicional, solo el JSON.
PROMPT;
    }

    private function callGeminiApi(string $prompt): string
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
                'maxOutputTokens' => 1024,
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
