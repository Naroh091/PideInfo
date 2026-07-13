<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent\Tool;

use App\Repository\JudgmentRepository;
use App\Service\AI\Agent\Tool\SearchJudgmentsTool;
use App\Service\AI\JudicialHistoryAnnotator;
use App\Service\Judgment\JudicialStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end over the REAL judgment corpus (needs `app:judgments:load-ctbg` run and vectors in
 * ai_judgments; skips itself otherwise). Makes one live embedding call per search.
 */
final class SearchJudgmentsToolTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        try {
            $vectors = (int) self::getContainer()->get(Connection::class)
                ->fetchOne('SELECT count(*) FROM ai_judgments');

            if ($vectors === 0) {
                self::markTestSkipped('ai_judgments está vacío (ejecuta app:judgments:load-ctbg).');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }
    }

    public function testFindsTheReelaboracionDoctrine(): void
    {
        // The RTVE case (SJCCA nº 9 60/2016) fixed the foundational doctrine on art. 18.1.c:
        // "reelaborar" vs information that already exists. It is in the corpus, verified.
        $tool = self::getContainer()->get(SearchJudgmentsTool::class);

        $output = $tool('la causa de inadmisión de reelaboración no ampara denegar información que existe');

        self::assertStringContainsString('sentencia', mb_strtolower($output));
        self::assertStringContainsString('sentido: **', $output);
        self::assertMatchesRegularExpression('/S(JCCA|AN|TS|TSJ)/', $output);
        // The honesty footer must always close the answer.
        self::assertStringContainsString('NUNCA inventes un ECLI', $output);
    }

    public function testStanceFilterNarrowsTheResults(): void
    {
        $tool = self::getContainer()->get(SearchJudgmentsTool::class);

        $output = $tool('límites al derecho de acceso a la información pública', stance: 'contra_acceso', topK: 3);

        if (str_contains($output, 'No hay sentencias analizadas')) {
            self::markTestSkipped('Aún no hay sentencias contra_acceso analizadas.');
        }

        self::assertStringContainsString('CONTRA el acceso', $output);
        self::assertStringNotContainsString('sentido: **PRO acceso**', $output);
    }

    public function testTheCrossAnnotatesARealLinkedResolution(): void
    {
        // Take any analyzed judgment that is actually linked to a resolution and check the
        // annotator produces a warning for that resolution — the real-data end of the rule
        // whose variants are pinned by the unit tests.
        $connection = self::getContainer()->get(Connection::class);

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT jr.resolution_id
            FROM judgment j
            JOIN judgment_resolution jr ON jr.judgment_id = j.id
            WHERE j.transparency_stance IS NOT NULL
            LIMIT 1
        SQL);

        if ($row === false) {
            self::markTestSkipped('Aún no hay sentencias analizadas enlazadas a resoluciones.');
        }

        $annotator = new JudicialHistoryAnnotator(self::getContainer()->get(JudgmentRepository::class));

        $results = $annotator->annotate([
            ['resolutionId' => (string) $row['resolution_id'], 'reference' => 'X'],
        ]);

        self::assertArrayHasKey('judicialHistory', $results[0]);
        self::assertNotSame(
            JudicialStatus::NOT_CHALLENGED,
            $results[0]['judicialHistory']['status'],
            'Una resolución con sentencia enlazada no puede aparecer como no recurrida.',
        );
        self::assertNotSame('', $results[0]['judicialHistory']['block']);
    }
}
