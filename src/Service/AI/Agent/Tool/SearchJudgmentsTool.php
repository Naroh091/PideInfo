<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\Judgment;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\JudgmentRetriever;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: case law that shapes the right of access.
 *
 * Above the CTBG's resolutions and criteria in the hierarchy of authority: a firm judgment of
 * the Tribunal Supremo binds the CTBG itself. The output leads with the citation label built
 * from what is actually KNOWN (ECLI only when the analyzer read it from the PDF) and with the
 * stance — quoting an anti-access judgment as if it helped is worse than quoting nothing.
 */
#[AsTool(
    name: 'search_judgments',
    description: 'Busca SENTENCIAS (Juzgados Centrales, Audiencia Nacional, Tribunal Supremo) que interpretan el derecho de acceso a la información, dictadas en recursos contra resoluciones del CTBG. Devuelve la cita canónica, el sentido del fallo, su efecto sobre la resolución recurrida, y la doctrina citable. Jerarquía: una sentencia firme del TS vale más que cualquier resolución o criterio del CTBG. Llámala una vez por argumento jurídico, junto a search_resolutions y search_criteria.',
)]
final class SearchJudgmentsTool
{
    private const MAX_OUTPUT_CHARS = 12_000;

    private const MAX_RESULTS = 8;

    private const STANCE_LABELS = [
        Judgment::STANCE_PRO_ACCESS => 'PRO acceso',
        Judgment::STANCE_ANTI_ACCESS => 'CONTRA el acceso',
        Judgment::STANCE_NEUTRAL => 'neutra (no entra al fondo)',
    ];

    public function __construct(
        private readonly JudgmentRetriever $retriever,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $argumentation El argumento jurídico que quieres apoyar o rebatir, en lenguaje natural. Ej.: "la reelaboración del art. 18.1.c no ampara denegar información que existe en las bases de datos del organismo".
     * @param string $stance        Filtro opcional por sentido: "pro_acceso", "contra_acceso" o "" (todas). Útil para anticipar la doctrina que la Administración citará en tu contra.
     * @param int    $topK          Sentencias a devolver (1-8, por defecto 4).
     */
    public function __invoke(string $argumentation, string $stance = '', int $topK = 4): string
    {
        $argumentation = trim($argumentation);
        if ($argumentation === '') {
            return 'Dime qué argumento jurídico quieres apoyar con jurisprudencia.';
        }

        $this->progress->step('Buscando jurisprudencia…', 'search_judgments');

        $stances = match (strtolower(trim($stance))) {
            Judgment::STANCE_PRO_ACCESS => [Judgment::STANCE_PRO_ACCESS],
            Judgment::STANCE_ANTI_ACCESS => [Judgment::STANCE_ANTI_ACCESS],
            default => null,
        };

        $results = $this->retriever->retrieve($argumentation, max(1, min($topK, self::MAX_RESULTS)), $stances);

        if ($results === []) {
            return 'No hay sentencias analizadas que casen con ese argumento. La jurisprudencia indexada cubre los '
                . 'recursos contra resoluciones del CTBG estatal; para doctrina administrativa usa search_resolutions '
                . 'y search_criteria.';
        }

        $blocks = [];
        $used = 0;

        foreach ($results as $result) {
            $block = $this->block($result['judgment'], $result['matchedText']);

            if ($used + mb_strlen($block) > self::MAX_OUTPUT_CHARS && $blocks !== []) {
                break;
            }

            $blocks[] = $block;
            $used += mb_strlen($block);
        }

        $header = sprintf('%d sentencia%s relevante%s:', count($blocks), count($blocks) === 1 ? '' : 's', count($blocks) === 1 ? '' : 's');

        $footer = '**Cita SOLO lo que aparece arriba.** Si una sentencia no trae ECLI, cítala por número y fecha '
            . '— NUNCA inventes un ECLI ni un fundamento jurídico que no hayas leído.';

        return implode("\n\n---\n\n", array_merge([$header], $blocks, [$footer]));
    }

    private function block(Judgment $judgment, string $matchedText): string
    {
        $lines = [];

        $lines[] = '### ' . $judgment->getCitationLabel();

        $facts = array_filter([
            $judgment->getInstanceLabel(),
            $judgment->isFinal() ? '**FIRME**' : 'no firme',
            'sentido: **' . (self::STANCE_LABELS[$judgment->getTransparencyStance()] ?? '?') . '**',
            $judgment->getResolutionEffect() !== null ? 'efecto sobre la resolución: ' . $judgment->getResolutionEffect() : null,
        ]);
        $lines[] = implode(' · ', $facts);

        if ($judgment->getSubject() !== null) {
            $lines[] = 'Asunto: ' . $judgment->getSubject();
        }

        if ($judgment->getChallengedResolutionRefs() !== []) {
            $lines[] = 'Resolución recurrida: ' . implode(', ', $judgment->getChallengedResolutionRefs());
        }

        // What the ruling MEANS, before anything else: the procedural triad does not tell a
        // drafter what the citizen actually got.
        if ($judgment->getEffectiveOutcome() !== null) {
            $lines[] = '';
            $lines[] = '**Qué decide:** ' . $judgment->getEffectiveOutcome();
        }

        if ($judgment->getSummary() !== null) {
            $lines[] = '';
            $lines[] = $judgment->getSummary();
        }

        foreach ($judgment->getDoctrine() as $doctrine) {
            $lines[] = '';
            $lines[] = sprintf(
                '> «%s»%s',
                $doctrine['quote'],
                $doctrine['basis'] !== '' ? ' — ' . $doctrine['basis'] : '',
            );
        }

        // The fragment the embedding matched, when it adds something the doctrine block missed.
        if ($judgment->getDoctrine() === [] && $matchedText !== '') {
            $lines[] = '';
            $lines[] = '> ' . mb_strimwidth($matchedText, 0, 600, '…');
        }

        return implode("\n", $lines);
    }
}
