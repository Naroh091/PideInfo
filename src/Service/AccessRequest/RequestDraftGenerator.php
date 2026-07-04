<?php

declare(strict_types=1);

namespace App\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Service\AccessRequest\Exception\RequestDraftNotGeneratedException;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ResolutionRetriever;
use Symfony\AI\Platform\Vector\Vector;

/**
 * Single-shot, non-streaming generation of a request draft. Shares the prompt
 * ({@see RequestPromptComposer}) and the draft-application rules with the
 * streaming chat flow ({@see \App\Controller\AssistantChatController}); that
 * controller delegates draft normalisation to {@see applyDraft} so both paths
 * stay in lockstep.
 *
 * The composer reads the draft channel (REG vs portal/email) off the
 * AccessRequest's RegDestination, so the entity must have its relations wired
 * before calling {@see generate} — it need not be persisted yet.
 */
final class RequestDraftGenerator
{
    /** Outcomes worth retrieving as drafting precedents. */
    private const RETRIEVAL_OUTCOMES = ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'];

    public function __construct(
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly LlmClient $llmClient,
    ) {
    }

    /**
     * Generate a draft for the request from a natural-language instruction and
     * apply it to the entity. Returns the normalised draft.
     *
     * @return array<string, mixed>
     *
     * @throws RequestDraftNotGeneratedException when the model asks for more
     *                                           context instead of drafting.
     */
    public function generate(AccessRequest $ar, string $userPrompt): array
    {
        $similar = $this->loadSimilarResolutions($ar, $userPrompt);
        $composed = $this->requestPromptComposer->compose($ar, $similar);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $composed,
            userText: $userPrompt,
            requiredJsonKeys: ['action'],
            label: 'RequestGenerationMcp',
            traceName: 'RequestGenerationMcp',
            promptRef: $composed,
        ));

        $action = (string) ($data['action'] ?? 'reply');
        $draft = $data['draft'] ?? null;

        if ($action === 'reply' || !is_array($draft)) {
            throw new RequestDraftNotGeneratedException((string) ($data['conversational_reply'] ?? ''));
        }

        return $this->applyDraft($ar, $draft);
    }

    /**
     * Normalise a draft payload and apply it to the request. For the REG channel
     * it fills `expone`/`solicita` and mirrors them into `description`; for the
     * portal/email channel it fills `description` directly. Shared with the
     * streaming controller.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function applyDraft(AccessRequest $ar, array $draft): array
    {
        $title = mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255);
        $ar->setTitle($title);

        if ($ar->getRegDestination() !== null) {
            $expone = mb_substr($this->plain((string) ($draft['expone'] ?? '')), 0, 4000);
            $solicita = mb_substr($this->plain((string) ($draft['solicita'] ?? '')), 0, 4000);
            $ar->setExpone($expone);
            $ar->setSolicita($solicita);
            $ar->setDescription(mb_substr(
                trim("EXPONE:\n" . $expone . "\n\nSOLICITA:\n" . $solicita),
                0,
                8500,
            ));

            return ['title' => $title, 'expone' => $expone, 'solicita' => $solicita];
        }

        $body = mb_substr($this->plain((string) ($draft['body_text'] ?? '')), 0, 3000);
        $ar->setDescription($body);

        return ['title' => $title, 'body_text' => $body];
    }

    /**
     * Retrieve drafting precedents most similar to the user's instruction. Uses
     * the vector store when the embedding backend is available, falling back to
     * the text retriever otherwise.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSimilarResolutions(AccessRequest $ar, string $userPrompt): array
    {
        $query = trim($userPrompt);
        if ($query === '') {
            $query = $ar->getPublicBody()->getName() . '. ' . ((string) ($ar->getApplicableLaw()?->getName() ?? ''));
        }

        try {
            $embedding = $this->embeddingGenerator->generate(mb_substr($query, 0, 4000));

            return $this->resolutionRetriever->retrieveSimilarCasesByVector(
                new Vector($embedding),
                3,
                self::RETRIEVAL_OUTCOMES,
            );
        } catch (\Throwable) {
            return $this->resolutionRetriever->retrieveSimilarCases(
                $query,
                3,
                self::RETRIEVAL_OUTCOMES,
            );
        }
    }

    private function plain(string $text): string
    {
        return trim(strip_tags($text));
    }
}
