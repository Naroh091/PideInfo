<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Entity\AccessRequest;
use App\Entity\LegalArticle;
use App\Repository\LegalArticleRepository;
use App\Service\Legal\ArticleRef;
use App\Service\Legal\CapacityLegalFramework;
use App\Service\Legal\KeyArticleSelector;
use App\Service\Legal\LegalCitationFormatter;
use App\Service\Legal\SubjectMatterFramework;
use App\Service\Legal\TrackedNorms;
use App\Service\Submission\RequesterCapacity;
use App\Service\Submission\RequesterCapacityResolver;

/**
 * The deterministic half of the legal framework.
 *
 * ApplicableLawResolver already knows which transparency law governs a given public body, so
 * there is no reason to make the agent guess it: its key articles (deadlines, limits,
 * inadmission causes, the complaint route) are pasted into the system prompt, literally, every
 * time. The tools then cover the variable half — the subject matter (LCSP, LGS…) and anything
 * the model decides it needs.
 *
 * Same role as WritingPreferencesFormatter, and injected the same way: in PHP, next to it.
 * NOT as a `{{legal_framework}}` template variable — the prompts are served by Langfuse, and a
 * version edited there without the variable would drop the whole block in silence.
 */
final class LegalFrameworkComposer
{
    /**
     * One budget per section, not a single pot. With a shared budget the applicable
     * transparency law (11 long articles) ate everything and the law of the subject matter —
     * the very thing the agent was quoting from memory — got nothing.
     *
     * These compete for context with the scaffolding, the resolutions and the draft, so they
     * are deliberately tight: the abridged form always fits, and the agent can always read more
     * with `read_law_articles`.
     */
    private const CONSTITUTION_BUDGET = 1_500;

    private const APPLICABLE_LAW_BUDGET = 8_000;

    private const SUBJECT_MATTER_BUDGET = 4_500;

    private const CAPACITY_BUDGET = 5_000;

    /** Abridged form of an article: rúbrica + opening paragraph + how to read the rest. */
    private const SHORT_FORM_CHARS = 400;

    /** What the abridged form actually costs once the citation header is added. */
    private const SHORT_FORM_RESERVE = 620;

    private const CONSTITUTION = 'BOE-A-1978-31229';

    public function __construct(
        private readonly LegalArticleRepository $articles,
        private readonly RequesterCapacityResolver $capacityResolver,
        private readonly KeyArticleSelector $keyArticleSelector,
    ) {
    }

    /** Returns '' when there is nothing to inject; the agent still has the tools. */
    public function compose(AccessRequest $request): string
    {
        $sections = [];

        $budget = self::CONSTITUTION_BUDGET;
        $constitutional = $this->constitutionalAnchor($budget);
        if ($constitutional !== '') {
            $sections[] = $constitutional;
        }

        $budget = self::APPLICABLE_LAW_BUDGET;
        $applicable = $this->applicableLawSection($request, $budget);
        if ($applicable !== '') {
            $sections[] = $applicable;
        }

        $budget = self::SUBJECT_MATTER_BUDGET;
        $subject = $this->subjectMatterSection($request, $budget);
        if ($subject !== '') {
            $sections[] = $subject;
        }

        $budget = self::CAPACITY_BUDGET;
        $capacity = $this->capacitySection($request, $budget);
        if ($capacity !== '') {
            $sections[] = $capacity;
        }

        if ($sections === []) {
            return '';
        }

        return "## Marco legal aplicable (texto literal y vigente — cítalo, no lo parafrasees de memoria)\n\n"
            . implode("\n\n", $sections)
            // Observed on real requests: the model would call read_law_articles on the very law
            // whose articles were already pasted above, burning an iteration and a chunk of
            // context for nothing.
            . "\n\n**Los artículos de arriba ya los tienes: no los vuelvas a leer con `read_law_articles`.** "
            . "Úsalos directamente. Las herramientas son para lo que NO está aquí: la ley de la materia "
            . "(contratación → LCSP, subvenciones → LGS, medio ambiente → Ley 27/2006…) o cualquier otro "
            . "precepto que necesites. **No cites de memoria ningún artículo que no hayas leído.**";
    }

    /**
     * The art. 105.b CE is the constitutional anchor of the right of access, and the model
     * opens almost every escrito with it. Before the Constitución was tracked, it cited it from
     * memory — precisely what this feature exists to prevent. It is one short article; the
     * budget can afford it.
     */
    private function constitutionalAnchor(int &$budget): string
    {
        $articles = $this->articles->findByRefs(self::CONSTITUTION, [
            new ArticleRef(LegalArticle::KIND_ARTICLE, 105, 105),
        ]);

        if ($articles === []) {
            return '';
        }

        return "### Anclaje constitucional\n\n" . $this->renderArticles($articles, $budget);
    }

    private function applicableLawSection(AccessRequest $request, int &$budget): string
    {
        $law = $request->getApplicableLaw();
        $boeId = $law->getBoeId();

        // Three rows of applicable_law still point at repealed or plain wrong statutes; they
        // are left with a null boe_id on purpose. Degrade quietly rather than inject a law we
        // know is not the right one.
        if ($boeId === null) {
            return '';
        }

        // The state law has a hand-picked, reviewed list. The 16 autonomous ones do not — and
        // hardcoding 16 sets of article numbers would need a lawyer and rot with every reform.
        // They copy the structure of the Ley 19/2013 almost verbatim, so their key articles are
        // selected by rúbrica instead. Without this, a request to any autonomous body got NO
        // deterministic block at all.
        $numbers = TrackedNorms::keyArticles($boeId);
        if ($numbers === []) {
            $numbers = $this->keyArticleSelector->select($boeId);
        }

        if ($numbers === []) {
            return '';
        }

        $articles = $this->articles->findByRefs($boeId, array_map(
            static fn (string $n): ArticleRef => new ArticleRef(LegalArticle::KIND_ARTICLE, (int) $n, (int) $n),
            $numbers,
        ));

        if ($articles === []) {
            return '';
        }

        $header = sprintf(
            '### %s%s — ley de transparencia aplicable a este organismo',
            $law->getName(),
            TrackedNorms::alias($boeId) !== null ? ' (' . TrackedNorms::alias($boeId) . ')' : '',
        );

        return $header . "\n\n" . $this->renderArticles($articles, $budget);
    }

    /**
     * The law of the subject matter. See SubjectMatterFramework for why this is deterministic
     * rather than left to the agent: it was quoting art. 118 LCSP from memory.
     */
    private function subjectMatterSection(AccessRequest $request, int &$budget): string
    {
        $subject = SubjectMatterFramework::detect($request);
        if ($subject === null) {
            return '';
        }

        $blocks = [];

        foreach ($subject['norms'] as $boeId => $numbers) {
            $articles = $this->articles->findByRefs($boeId, array_map(
                static fn (string $n): ArticleRef => new ArticleRef(LegalArticle::KIND_ARTICLE, (int) $n, (int) $n),
                $numbers,
            ));

            if ($articles !== []) {
                $blocks[] = $this->renderArticles($articles, $budget);
            }
        }

        if ($blocks === []) {
            return '';
        }

        return implode("\n\n", array_merge(
            [sprintf('### Ley de la materia: %s', $subject['label'])],
            $blocks,
            [$subject['directive']],
        ));
    }

    private function capacitySection(AccessRequest $request, int &$budget): string
    {
        ['capacity' => $capacity, 'detail' => $detail] = $this->capacityResolver->for($request);

        if (!CapacityLegalFramework::has($capacity)) {
            return '';
        }

        $blocks = [];

        foreach (CapacityLegalFramework::norms($capacity) as $boeId => $numbers) {
            $articles = $this->articles->findByRefs($boeId, array_map(
                static fn (string $n): ArticleRef => new ArticleRef(LegalArticle::KIND_ARTICLE, (int) $n, (int) $n),
                $numbers,
            ));

            if ($articles !== []) {
                $blocks[] = $this->renderArticles($articles, $budget);
            }
        }

        $directives = CapacityLegalFramework::directives($capacity) ?? '';
        $directives = $detail !== null
            ? $directives . "\n\nCondición declarada por el usuario: **" . $detail . '**.'
            : $directives;

        $header = sprintf('### Condición en que se ejerce el derecho: %s', RequesterCapacity::label($capacity));

        return implode("\n\n", array_filter([$header, ...$blocks, $directives]));
    }

    /**
     * Every requested article gets in — the only question is whether in full or abridged.
     *
     * The naive "spend until the budget runs out" version dropped arts. 19 to 24 of the
     * LTAIBG on every real request, because arts. 14 and 15 are long. Among the casualties was
     * **art. 20 — the deadline and the sense of the silence**, which is the single article a
     * drafter is most likely to need. Silently. So the budget now reserves the abridged form
     * for all of them first, and only then spends what is left on full texts.
     *
     * @param list<LegalArticle> $articles
     */
    private function renderArticles(array $articles, int &$budget): string
    {
        $blocks = [];
        $remaining = count($articles);

        foreach ($articles as $article) {
            $short = LegalCitationFormatter::block($article, self::SHORT_FORM_CHARS);
            $full = LegalCitationFormatter::block($article);

            // Keep enough back for the abridged form of every article still to come.
            $reserved = ($remaining - 1) * self::SHORT_FORM_RESERVE;
            $affordable = $budget - $reserved;

            if (mb_strlen($full) <= $affordable) {
                $blocks[] = $full;
                $budget -= mb_strlen($full);
            } elseif (mb_strlen($short) <= $budget) {
                $blocks[] = $short;
                $budget -= mb_strlen($short);
            } else {
                break;   // nothing left even for the abridged form
            }

            --$remaining;
        }

        return implode("\n\n", $blocks);
    }
}
