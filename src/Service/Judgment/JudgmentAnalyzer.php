<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\Entity\Judgment;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;

/**
 * LLM analysis of a judgment's full text: the outcome/effect/stance triad, citable doctrine,
 * ECLI, dates.
 *
 * Merge discipline (apply()): the XLSX listing owns the case facts it printed — judgment
 * number, firmeza, appellant type — and the analyzer NEVER touches them; the analyzer owns
 * only what it read out of the PDF. Two sources of truth per field is how silent corruption
 * starts.
 */
final class JudgmentAnalyzer
{
    private const OUTCOMES = [
        Judgment::OUTCOME_ESTIMATORIA, Judgment::OUTCOME_ESTIMATORIA_PARCIAL,
        Judgment::OUTCOME_DESESTIMATORIA, Judgment::OUTCOME_INADMISION,
        Judgment::OUTCOME_ARCHIVO, Judgment::OUTCOME_DESISTIMIENTO,
    ];

    private const EFFECTS = [
        Judgment::EFFECT_CONFIRMA, Judgment::EFFECT_ANULA,
        Judgment::EFFECT_ANULA_PARCIAL, Judgment::EFFECT_RETROTRAE,
    ];

    private const STANCES = [
        Judgment::STANCE_PRO_ACCESS, Judgment::STANCE_ANTI_ACCESS, Judgment::STANCE_NEUTRAL,
    ];

    private const MAX_TEXT_CHARS = 240_000;

    /** The tail always survives: fundamentos finales + fallo. Never trimmed below this. */
    private const TAIL_CHARS = 45_000;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $fullText): array
    {
        $prompt = $this->promptStore->compile('pideinfo-judgment-extract-analysis');

        return $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $prompt,
            userText: self::excerpt($fullText),
            requiredJsonKeys: ['summary', 'outcome', 'transparency_stance'],
            maxOutputTokens: 4096,
            label: 'JudgmentAnalysis',
            traceName: 'JudgmentAnalysis',
        ));
    }

    /**
     * Head + tail, with the middle elided and SAID SO. The tail is sacred: the FALLO is the
     * last thing in a sentencia, and a judgment analysed without its fallo is not analysed —
     * it is inverted.
     */
    public static function excerpt(string $fullText): string
    {
        if (mb_strlen($fullText) <= self::MAX_TEXT_CHARS) {
            return $fullText;
        }

        $headChars = self::MAX_TEXT_CHARS - self::TAIL_CHARS;

        return mb_substr($fullText, 0, $headChars)
            . "\n\n[…SE HA OMITIDO UNA PARTE CENTRAL DE LA SENTENCIA POR LONGITUD. "
            . "EL TEXTO CONTINÚA CON SUS FUNDAMENTOS FINALES Y SU FALLO…]\n\n"
            . mb_substr($fullText, -self::TAIL_CHARS);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function apply(Judgment $judgment, array $result): void
    {
        $judgment->setSummary(self::str($result['summary'] ?? null));
        $judgment->setEffectiveOutcome(self::str($result['effective_outcome'] ?? null));
        $judgment->setKeypoints(self::strList($result['keypoints'] ?? null));
        $judgment->setDoctrine(self::doctrine($result['doctrine'] ?? null));
        $judgment->setInterpretedArticles(self::strList($result['interpreted_articles'] ?? null));
        $judgment->setKeywords(self::strList($result['keywords'] ?? null));
        $judgment->setTopics(self::strList($result['topics'] ?? null));
        $judgment->setChamber(self::str($result['chamber'] ?? null, 60));

        // Everything below decides how the agent may USE the judgment, so garbage degrades to
        // null (= "unanalysed", the retriever skips it) instead of being stored as-is.
        $judgment->setOutcome(self::enum($result['outcome'] ?? null, self::OUTCOMES));
        $judgment->setResolutionEffect(self::enum($result['resolution_effect'] ?? null, self::EFFECTS));
        $judgment->setTransparencyStance(self::enum($result['transparency_stance'] ?? null, self::STANCES));

        $ecli = self::str($result['ecli'] ?? null, 40);
        if ($ecli !== null && preg_match('/^ECLI:ES:[A-Z]{2,4}:\d{4}:[0-9A-Z]+$/', strtoupper($ecli))) {
            $judgment->setEcli(strtoupper($ecli));
        }

        $judgment->setRoj(self::str($result['roj'] ?? null, 40));

        $date = self::str($result['judgment_date'] ?? null);
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed !== false) {
                $judgment->setJudgmentDate($parsed);
            }
        }
    }

    private static function str(mixed $value, ?int $max = null): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }

    /** @return list<string>|null */
    private static function strList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $list = array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $value),
            static fn (string $v): bool => $v !== '',
        ));

        return $list !== [] ? $list : null;
    }

    /** @return list<array{quote: string, basis: string}>|null */
    private static function doctrine(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item) && is_string($item['quote'] ?? null) && trim($item['quote']) !== '') {
                $list[] = [
                    'quote' => trim($item['quote']),
                    'basis' => is_string($item['basis'] ?? null) ? trim($item['basis']) : '',
                ];
            }
        }

        return $list !== [] ? $list : null;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }
}
