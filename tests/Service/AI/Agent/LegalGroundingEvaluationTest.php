<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Entity\AccessRequest;
use App\Entity\LegalArticle;
use App\Repository\AccessRequestRepository;
use App\Repository\LegalArticleRepository;
use App\Repository\LegalNormRepository;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\Legal\LegalNormReader;
use App\Service\Legal\TrackedNorms;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Evaluation harness (opt-in, makes live LLM calls): runs the real agent over every access
 * request in the database and checks, for each drafted request, whether the law it cites is
 * REAL and whether it had actually READ it.
 *
 * The rule the whole feature exists to enforce is "never cite an article you have not read".
 * This is what measures it:
 *
 *   - GROUNDED    the cited article was pre-injected or returned by a tool in this turn
 *   - UNGROUNDED  the article exists, but the agent never saw its text → cited from memory
 *   - INVENTED    the article does not exist in that norm at all → hard error
 *
 * Writes one JSON record per request to EVAL_OUT.
 */
final class LegalGroundingEvaluationTest extends KernelTestCase
{
    public function testEvaluateAllRequests(): void
    {
        if (($_SERVER['PROBE_LLM'] ?? '') !== '1') {
            self::markTestSkipped('Evaluación con LLM real. Ejecuta con PROBE_LLM=1.');
        }

        self::bootKernel();
        $c = self::getContainer();

        $out = (string) ($_SERVER['EVAL_OUT'] ?? '/tmp/eval.jsonl');
        $handle = fopen($out, 'wb');
        self::assertIsResource($handle);

        $qb = $c->get(AccessRequestRepository::class)->createQueryBuilder('ar')
            ->join('ar.applicableLaw', 'al')->addSelect('al')
            ->join('ar.publicBody', 'pb')->addSelect('pb')
            ->join('ar.user', 'u')->addSelect('u')
            ->where('ar.title IS NOT NULL')
            ->andWhere("ar.title <> ''")
            ->orderBy('ar.createdAt', 'DESC')
            ->addOrderBy('ar.id', 'ASC');                    // deterministic across shards

        if (($filter = $_SERVER['EVAL_FILTER'] ?? '') !== '') {
            $qb->andWhere('LOWER(ar.title) LIKE :f OR LOWER(ar.description) LIKE :f')
                ->setParameter('f', '%' . mb_strtolower((string) $filter) . '%');
        }

        $requests = $qb
            ->setFirstResult((int) ($_SERVER['EVAL_OFFSET'] ?? 0))
            ->setMaxResults((int) ($_SERVER['EVAL_LIMIT'] ?? 200))
            ->getQuery()->getResult();

        $composer = $c->get(RequestPromptComposer::class);
        $legal = $c->get(LegalFrameworkComposer::class);
        $orchestrator = $c->get(AgentChatOrchestrator::class);
        $tokenStorage = $c->get('security.token_storage');

        $done = 0;
        foreach ($requests as $ar) {
            /** @var AccessRequest $ar */
            $record = $this->evaluate($ar, $composer, $legal, $orchestrator, $tokenStorage, $c);
            fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n");
            fflush($handle);

            fwrite(STDERR, sprintf(
                "[%3d/%3d] %-52s cita:%-2d sin-leer:%-2d inventadas:%-2d %s\n",
                ++$done,
                count($requests),
                mb_strimwidth((string) $ar->getTitle(), 0, 50, '…'),
                count($record['cited']),
                count($record['ungrounded']),
                count($record['invented']),
                $record['error'] ?? '',
            ));
        }

        fclose($handle);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, mixed> */
    private function evaluate(
        AccessRequest $ar,
        RequestPromptComposer $composer,
        LegalFrameworkComposer $legal,
        AgentChatOrchestrator $orchestrator,
        object $tokenStorage,
        object $container,
    ): array {
        $record = [
            'id' => (string) $ar->getId(),
            'title' => $ar->getTitle(),
            'body' => $ar->getPublicBody()->getName(),
            'law' => $ar->getApplicableLaw()->getShortCode(),
            'lawBoeId' => $ar->getApplicableLaw()->getBoeId(),
            'preinjected' => [],
            'tools' => [],
            'toolArticles' => [],
            'normsOpened' => [],
            'cited' => [],
            'ungrounded' => [],
            'invented' => [],
            'orphaned' => [],
            'action' => null,
            'draftChars' => 0,
            'error' => null,
        ];

        try {
            $block = $legal->compose($ar);
            $record['preinjected'] = $this->articlesIn($block);

            $tokenStorage->setToken(new UsernamePasswordToken($ar->getUser(), 'main', $ar->getUser()->getRoles()));
            $prompt = $composer->compose($ar, []);

            $req = new AssistantChatRequest(
                flow: 'request',
                entityId: (string) $ar->getId(),
                systemPrompt: $prompt->text,
                userMessage: 'Redacta la solicitud.',
                history: [],
                attachments: [],
                label: 'LegalGroundingEval',
                promptRef: $prompt,
                traceName: 'LegalGroundingEval',
            );

            $decision = null;
            $toolOutput = '';
            $reply = '';

            foreach ($orchestrator->stream($req) as [$event, $payload]) {
                if ($event === 'chat_token') {
                    $reply .= (string) ($payload['text'] ?? '');
                } elseif ($event === 'step') {
                    $message = (string) ($payload['message'] ?? '');
                    if (($tool = $payload['tool'] ?? null) !== null) {
                        $record['tools'][] = $tool;
                    }
                    $toolOutput .= "\n" . $message;
                } elseif ($event === 'decision') {
                    $decision = $payload;
                } elseif ($event === 'error') {
                    $record['error'] = 'stream: ' . ($payload['message'] ?? '?');
                }
            }

            $record['tools'] = array_values(array_unique($record['tools']));
            $record['toolArticles'] = $this->articlesIn($toolOutput);
            $record['normsOpened'] = $this->normsOpened($toolOutput, $record['preinjected']);

            $draft = $decision['draft'] ?? [];
            $body = strip_tags(implode(' ', array_filter([
                $draft['body_html'] ?? null,
                $draft['body_text'] ?? null,
                $draft['expone'] ?? null,
                $draft['solicita'] ?? null,
            ])));

            $record['action'] = $decision['action'] ?? null;
            $record['draftChars'] = mb_strlen($body);
            $record['reply'] = mb_strimwidth(trim(strip_tags($reply)), 0, 240, '…');
            // Kept verbatim so a mistake in the citation analysis can be corrected offline,
            // without paying for 159 more LLM runs. (It already happened once.)
            $record['draft'] = $body;
            $record['steps'] = mb_strimwidth($toolOutput, 0, 4000, '…');
            $record['cited'] = $this->citationsIn($body, $ar->getApplicableLaw()->getBoeId(), $container);

            // Grounding is judged at NORM level, not article level: the SSE `step` payload only
            // carries a truncated preview of each tool result, so we cannot know exactly which
            // articles came back — but we do know which norms the agent opened. Claiming an
            // article was "cited from memory" on truncated evidence would be dishonest.
            $opened = array_flip($record['normsOpened']);

            // A citation whose article does not exist in the norm we attributed it to is either
            // a hallucination or a bad attribution on our side. Before accusing, try the other
            // norms the draft names: "art. 118.2" in a paragraph that also mentions the LCSP is
            // an LCSP citation, however far away the words are.
            $named = array_values(array_unique(array_column($record['cited'], 'boeId')));

            foreach ($record['cited'] as $citation) {
                if (!$this->exists($citation['boeId'], $citation['number'], $container)) {
                    $rescued = null;

                    foreach ($named as $candidate) {
                        if ($candidate !== $citation['boeId'] && $this->exists($candidate, $citation['number'], $container)) {
                            $rescued = $candidate;
                            break;
                        }
                    }

                    if ($rescued === null) {
                        // Nowhere to put it: the escrito cites an article that does not exist in
                        // ANY of the laws it names. That is a real defect — a reader would read
                        // it as belonging to the transparency law.
                        $record['invented'][] = $citation;

                        continue;
                    }

                    $citation['boeId'] = $rescued;
                    $citation['law'] = TrackedNorms::alias($rescued) ?? $rescued;
                    $record['orphaned'][] = $citation;   // real but unattributed in the draft
                }

                if (!isset($opened[$citation['boeId']])) {
                    $record['ungrounded'][] = $citation;
                }
            }
        } catch (\Throwable $e) {
            $record['error'] = get_class($e) . ': ' . mb_strimwidth($e->getMessage(), 0, 160, '…');
        }

        return $record;
    }

    /**
     * Every article the agent actually SAW, as rendered by LegalCitationFormatter:
     * "### art. 118 LCSP (Ley 9/2017) — …" / "### [DEROGADO] art. 30 …".
     *
     * @return list<string> "boeId#number"
     */
    private function articlesIn(string $text): array
    {
        if (!preg_match_all('/###\s*(?:\[DEROGADO\]\s*)?art\.\s*([\d]+)(?:\s+(bis|ter))?\s+([A-ZÁÉÍÓÚÑ][\w\-]*)/u', $text, $m, PREG_SET_ORDER)) {
            return [];
        }

        $seen = [];
        foreach ($m as $hit) {
            $boeId = TrackedNorms::byAlias($hit[3]);
            if ($boeId !== null) {
                $seen[] = $boeId . '#' . $hit[1];
            }
        }

        return array_values(array_unique($seen));
    }

    /**
     * Which norms the agent actually opened this turn: the ones pre-injected into the prompt,
     * plus the ones a legal tool reported on ("Leyendo el articulado de LCSP…", "### art. 118
     * LCSP …").
     *
     * @param list<string> $preinjected
     *
     * @return list<string> boeIds
     */
    private function normsOpened(string $toolOutput, array $preinjected): array
    {
        $opened = [];

        foreach ($preinjected as $key) {
            $opened[] = explode('#', $key)[0];
        }

        if (preg_match_all('/(?:articulado de|art\.\s*\d+(?:\s+bis)?)\s+([A-ZÁÉÍÓÚÑ][\w\-]{1,10})/u', $toolOutput, $m)) {
            foreach ($m[1] as $alias) {
                $boeId = TrackedNorms::byAlias($alias);
                if ($boeId !== null) {
                    $opened[] = $boeId;
                }
            }
        }

        return array_values(array_unique($opened));
    }

    /**
     * Articles cited in the DRAFT. Attributes each one to a norm: the law named next to it,
     * otherwise the last law named before it, otherwise the applicable law of the request.
     *
     * @return list<array{number: string, boeId: string, law: string, raw: string}>
     */
    private function citationsIn(string $body, ?string $applicableBoeId, object $container): array
    {
        $norms = $container->get(LegalNormRepository::class);

        // Where each law is named, so an article can be attributed to the nearest one.
        $lawAt = [];
        if (preg_match_all('/\b(?:Ley(?:\s+Orgánica)?|Real\s+Decreto(?:\s+Legislativo)?)\s+(\d+\/\d{4})|\b(LCSP|LBRL|ROF|LTAIBG|LTAIPBG|LPACAP|LRJSP|LJCA|LOPDGDD|LGS|TREBEP|TRLRHL|LAIMA)\b|\b(Constituci[óo]n(?:\s+Española)?|CE)\b/u', $body, $lm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($lm as $hit) {
                $offset = $hit[0][1];

                if (($hit[1][0] ?? '') !== '') {
                    $lawAt[$offset] = $this->normByNumber($hit[1][0], $container);
                } elseif (($hit[2][0] ?? '') !== '') {
                    $alias = strtoupper($hit[2][0]);
                    // The database stores the state law under the odd short_code LTAIPBG; the
                    // model repeats it back. Same norm.
                    $lawAt[$offset] = TrackedNorms::byAlias($alias === 'LTAIPBG' ? 'LTAIBG' : $alias);
                } else {
                    $lawAt[$offset] = 'BOE-A-1978-31229';   // Constitución
                }
            }
        }
        ksort($lawAt);

        if (!preg_match_all('/\bart[íi]?c?u?l?o?s?\.?\s*(\d+)(?:\.\d+)*(?:\.[a-z]\)?)?/iu', $body, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $citations = [];

        foreach ($am as $hit) {
            $number = $hit[1][0];
            $offset = $hit[0][1];
            $end = $offset + mb_strlen($hit[0][0]) + 90;   // "art. 118.2 de la Ley 9/2017 (LCSP)"

            $boeId = null;
            foreach ($lawAt as $lawOffset => $candidate) {
                if ($lawOffset >= $offset && $lawOffset <= $end && $candidate !== null) {
                    $boeId = $candidate;   // named right after the article: the strongest signal
                    break;
                }
            }

            if ($boeId === null) {
                foreach ($lawAt as $lawOffset => $candidate) {
                    if ($lawOffset < $offset && $candidate !== null) {
                        $boeId = $candidate;   // otherwise the law in force in the paragraph
                    }
                }
            }

            $boeId ??= $applicableBoeId;

            if ($boeId === null) {
                continue;
            }

            $citations[] = [
                'number' => $number,
                'boeId' => $boeId,
                'law' => TrackedNorms::alias($boeId) ?? $boeId,
                'raw' => trim($hit[0][0]),
            ];
        }

        // Dedupe by (norm, article).
        $unique = [];
        foreach ($citations as $citation) {
            $unique[$citation['boeId'] . '#' . $citation['number']] = $citation;
        }

        return array_values($unique);
    }

    /**
     * "Ley 9/2017" → the LCSP, not one of the many autonomous norms that share the number.
     *
     * Ordering by publication date alone attributed "art. 118.2 LCSP" to a Navarrese
     * decreto-ley and even to an Andalusian BOJA entry, which then showed up as the agent
     * "inventing" articles. Tracked and state norms come first; among equals, a `ley` beats a
     * decreto.
     */
    private function normByNumber(string $officialNumber, object $container): ?string
    {
        static $cache = [];

        if (array_key_exists($officialNumber, $cache)) {
            return $cache[$officialNumber];
        }

        $sql = <<<'SQL'
            SELECT boe_id FROM legal_norm
            WHERE official_number = :num
            ORDER BY tracked DESC,
                     (jurisdiction = 'es') DESC,
                     (norm_rank IN ('ley', 'ley_organica', 'constitucion')) DESC,
                     (norm_rank IN ('real_decreto', 'real_decreto_legislativo')) DESC,
                     publication_date ASC
            LIMIT 1
        SQL;

        $boeId = $container->get('doctrine')->getConnection()
            ->fetchOne($sql, ['num' => $officialNumber]);

        return $cache[$officialNumber] = is_string($boeId) ? $boeId : null;
    }

    /** Does that article actually exist in that norm? Tracked → database; otherwise → disk. */
    private function exists(string $boeId, string $number, object $container): bool
    {
        static $cache = [];
        $key = $boeId . '#' . $number;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $articles = $container->get(LegalArticleRepository::class);
        $norms = $container->get(LegalNormRepository::class);
        $reader = $container->get(LegalNormReader::class);

        $norm = $norms->findByBoeId($boeId);
        if ($norm === null) {
            return $cache[$key] = true;   // norm outside the corpus: cannot judge, do not accuse
        }

        if ($norm->isTracked() && $norm->hasArticles()) {
            foreach ($articles->findOutline($boeId) as $row) {
                if ($row['kind'] === LegalArticle::KIND_ARTICLE && (string) $row['number'] === $number) {
                    return $cache[$key] = true;
                }
            }

            return $cache[$key] = false;
        }

        foreach ($reader->readArticles($norm) as $article) {
            if ($article->getKind() === LegalArticle::KIND_ARTICLE && (string) $article->getNumberInt() === $number) {
                return $cache[$key] = true;
            }
        }

        return $cache[$key] = false;
    }
}
