<?php

declare(strict_types=1);

namespace App\Tests\Service\Judgment;

use App\Entity\Judgment;
use App\Repository\JudgmentRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\JudgmentRetriever;
use App\Service\Judgment\JudgmentAnalyzer;
use App\Service\Judgment\JudgmentVectorizer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single-writer metadata rule with reflection on the REAL method, not a copy:
 * `judgment_id` must be unconditionally present in every vector's metadata.
 *
 * Why this deserves its own test: the resolution pipeline has two vectorization code paths,
 * and the inline one forgot to write `resolution_id` — every vector it produced became
 * INVISIBLE to the retriever, silently. Judgments have ONE writer and this test keeps its
 * contract honest.
 */
final class JudgmentVectorizerMetadataTest extends TestCase
{
    public function testBaseMetadataAlwaysCarriesTheJudgmentId(): void
    {
        $method = new \ReflectionMethod(JudgmentVectorizer::class, 'baseMetadata');

        // A judgment as bare as the importer can produce: no analysis, no stance, no subject.
        $bare = (new Judgment())
            ->setReferenceNumber('JCCA1/1/2020')
            ->setCourt(Judgment::COURT_JCCA);

        $vectorizer = (new \ReflectionClass(JudgmentVectorizer::class))->newInstanceWithoutConstructor();
        $metadata = $method->invoke($vectorizer, $bare);

        self::assertArrayHasKey('judgment_id', $metadata);
        self::assertSame((string) $bare->getId(), $metadata['judgment_id']);
        // array_filter must never eat it: it is a UUID string, always truthy — but if someone
        // "refactors" it into the filtered optional block, this is the alarm.
        self::assertNotEmpty($metadata['judgment_id']);
    }

    public function testTheRetrieverNeverServesUnanalysedJudgments(): void
    {
        // transparencyStance decides how a judgment may be used in a written argument.
        // Serving one without it hands the drafter a weapon with the safety filed off.
        $unanalysed = (new Judgment())
            ->setReferenceNumber('AN/1/2020')
            ->setCourt(Judgment::COURT_AN);

        self::assertNull($unanalysed->getTransparencyStance());

        // The gate lives in JudgmentRetriever::retrieve() (stance check on rehydration) and is
        // asserted end-to-end by the kernel probe; here we pin the entity-level invariant the
        // gate relies on: a fresh judgment has NO stance until the analyzer sets a valid one.
        $retrieverExists = class_exists(JudgmentRetriever::class)
            && (new \ReflectionClass(JudgmentRetriever::class))->hasMethod('retrieve');
        self::assertTrue($retrieverExists);

        // And the analyzer refuses to set garbage.
        $analyzer = (new \ReflectionClass(\App\Service\Judgment\JudgmentAnalyzer::class))->newInstanceWithoutConstructor();
        $analyzer->apply($unanalysed, ['summary' => 'x', 'outcome' => 'lo que sea', 'transparency_stance' => 'favorable']);

        self::assertNull($unanalysed->getOutcome(), 'Un outcome fuera del vocabulario debe degradar a null.');
        self::assertNull($unanalysed->getTransparencyStance(), 'Un stance fuera del vocabulario debe degradar a null.');
    }

    public function testValidEnumsAreApplied(): void
    {
        // judgmentNumber is set by the importer (from the XLSX), never by the analyzer.
        $judgment = (new Judgment())
            ->setReferenceNumber('TS/1547/2017')
            ->setCourt(Judgment::COURT_TS)
            ->setJudgmentNumber('1547/2017');

        $analyzer = (new \ReflectionClass(\App\Service\Judgment\JudgmentAnalyzer::class))->newInstanceWithoutConstructor();
        $analyzer->apply($judgment, [
            'summary' => 'Resumen.',
            'outcome' => 'desestimatoria',
            'resolution_effect' => 'confirma',
            'transparency_stance' => 'pro_acceso',
            'ecli' => 'ECLI:ES:TS:2017:3626',
            'judgment_date' => '2017-10-16',
            'doctrine' => [['quote' => 'La información pública…', 'basis' => 'Ley 19/2013 art. 13']],
        ]);

        self::assertSame(Judgment::OUTCOME_DESESTIMATORIA, $judgment->getOutcome());
        self::assertSame(Judgment::EFFECT_CONFIRMA, $judgment->getResolutionEffect());
        self::assertSame(Judgment::STANCE_PRO_ACCESS, $judgment->getTransparencyStance());
        self::assertSame('ECLI:ES:TS:2017:3626', $judgment->getEcli());
        self::assertSame('2017-10-16', $judgment->getJudgmentDate()?->format('Y-m-d'));
        self::assertStringContainsString('STS 1547/2017', $judgment->getCitationLabel());
        self::assertStringContainsString('ECLI:ES:TS:2017:3626', $judgment->getCitationLabel());
    }

    public function testTheFalloAlwaysSurvivesTheExcerpt(): void
    {
        // THE bug of this feature, caught on the BOSCO STS: the FALLO sat at char 178.583 of
        // 181.158 and a head-only truncation cut it off. The model then read only the
        // ANTECEDENTES — which transcribe the APPEALED judgment's dismissive reasoning for
        // dozens of pages — and reported the exact opposite outcome: an estimatoria that
        // granted access to the source code was stored as desestimatoria/confirma/contra_acceso.
        $antecedentes = str_repeat(
            'ANTECEDENTES. La sentencia apelada desestima el recurso y confirma la denegación. ',
            4000,
        );
        $fallo = "\n\nF A L L O\n\n1º Declarar haber lugar al recurso de casación. "
            . '2º Anulamos la resolución administrativa recurrida por ser contraria a derecho. '
            . 'Declaramos el derecho de la Fundación Ciudadana Civio a acceder al código fuente.';

        $fullText = $antecedentes . $fallo;
        self::assertGreaterThan(240_000, mb_strlen($fullText), 'El fixture debe superar el cap para que el recorte actúe.');

        $excerpt = JudgmentAnalyzer::excerpt($fullText);

        self::assertStringContainsString('Declarar haber lugar al recurso de casación', $excerpt);
        self::assertStringContainsString('Anulamos la resolución administrativa recurrida', $excerpt);
        self::assertStringContainsString('acceder al código fuente', $excerpt);

        // And the elision must be announced, so the model knows the middle is missing and the
        // fallo is still ahead.
        self::assertStringContainsString('SE HA OMITIDO UNA PARTE CENTRAL', $excerpt);
    }

    public function testAShortJudgmentIsNotTouched(): void
    {
        $text = "ANTECEDENTES.\n\nF A L L O\n\nDesestimamos el recurso.";

        self::assertSame($text, JudgmentAnalyzer::excerpt($text));
    }

    public function testACorruptEcliIsRefused(): void
    {
        $judgment = (new Judgment())->setReferenceNumber('AN/2/2020')->setCourt(Judgment::COURT_AN);

        $analyzer = (new \ReflectionClass(\App\Service\Judgment\JudgmentAnalyzer::class))->newInstanceWithoutConstructor();
        $analyzer->apply($judgment, ['summary' => 'x', 'ecli' => 'ECLI-inventado-123']);

        self::assertNull($judgment->getEcli(), 'Un ECLI que no cumple el formato oficial no se guarda: antes null que inventado.');
    }
}
