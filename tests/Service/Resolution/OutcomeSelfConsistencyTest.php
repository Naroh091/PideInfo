<?php

declare(strict_types=1);

namespace App\Tests\Service\Resolution;

use App\Entity\Resolution;
use App\Service\Resolution\ResolutionAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * The rule that catches the analysis contradicting itself: a summary that says the Council
 * partially estimated the reclamación cannot sit next to an outcome of `favorable`.
 *
 * Found through R/0701/2018 (BOSCO), where exactly that happened — and where the wrong label
 * then overwrote the `partial` the CTBG listing had got right.
 */
final class OutcomeSelfConsistencyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function summaries(): iterable
    {
        // The Council partially estimating the CLAIM: that is a partial outcome.
        yield 'BOSCO, literal' => [
            'El Consejo de Transparencia y Buen Gobierno estima parcialmente la reclamación, denegando '
            . 'solo el código fuente por propiedad intelectual y ordenando la entrega del resto.',
            true,
        ];
        yield 'past tense' => ['El Consejo estimó parcialmente la reclamación, instando a la ITSS a responder.', true];
        yield 'passive' => ['Fue parcialmente estimada la reclamación del interesado.', true];
        yield 'nominal' => ['Se acuerda la estimación parcial de la reclamación presentada.', true];

        // Dismissing a claim in part IS estimating it in part. Neither a total win nor a total loss.
        yield 'partial dismissal' => [
            'El Consejo de Transparencia y Protección de Datos de Andalucía desestima parcialmente la reclamación.',
            true,
        ];

        // THE TRAP. Here it is the ADMINISTRATION that partially granted the original request —
        // often the very reason the Council then DISMISSES the claim (nothing left to order).
        // Reading this as `partial` would invent a win that never happened.
        yield 'the administration partially granted the request' => [
            'La Consejería estimó parcialmente la solicitud, denegando los datos de sanciones. '
            . 'El Consejo desestima la reclamación al haberse entregado ya lo procedente.',
            false,
        ];
        yield 'partial reply by the body' => [
            'Tras una respuesta parcial del Ayuntamiento, el Consejo estima la reclamación en su integridad.',
            false,
        ];
        yield 'partial access, not a partial ruling' => [
            'La Administración concedió un acceso parcial a los expedientes. El Consejo estima la reclamación.',
            false,
        ];
        yield 'a plain estimation' => ['El Consejo estima la reclamación y ordena entregar la información.', false];
        yield 'a plain dismissal' => ['El Consejo desestima la reclamación por resultar abusiva.', false];
    }

    /**
     * @dataProvider summaries
     */
    public function testItRecognisesOnlyAPartialRulingOnTheClaim(string $summary, bool $expected): void
    {
        self::assertSame($expected, ResolutionAnalyzer::summarySaysPartial($summary));
    }

    private function resolution(string $summary, string $outcome): Resolution
    {
        return (new Resolution())
            ->setReferenceNumber('R/0001/2020')
            ->setSummary($summary)
            ->setFullText('…')
            ->setOutcome($outcome);
    }

    /** The self signal: the label and the summary written in the same pass disagree. */
    public function testItFlagsAnAnalysisThatContradictsItself(): void
    {
        $resolution = $this->resolution('El Consejo estima parcialmente la reclamación.', Resolution::OUTCOME_FAVORABLE);

        self::assertSame('self', ResolutionAnalyzer::contradiction($resolution, Resolution::OUTCOME_FAVORABLE));
    }

    /**
     * The source signal, and BOSCO's original sin: the council's listing published `partial` and
     * the model demoted it to a total outcome. Demoting is destroying information.
     */
    public function testItFlagsTheModelDemotingThePartialTheListingPublished(): void
    {
        // At ingestion the imported value is still the stored one.
        $atIngestion = $this->resolution('Resumen sin pistas.', Resolution::OUTCOME_PARTIAL);
        self::assertSame('source', ResolutionAnalyzer::contradiction($atIngestion, Resolution::OUTCOME_FAVORABLE));

        // After the override, only the metadata remembers what the listing said.
        $afterOverride = $this->resolution('Resumen sin pistas.', Resolution::OUTCOME_FAVORABLE);
        $afterOverride->setSourceMetadata([
            Resolution::META_OUTCOME_OVERRIDEN => ['previous' => Resolution::OUTCOME_PARTIAL, 'new' => Resolution::OUTCOME_FAVORABLE],
        ]);
        self::assertSame('source', ResolutionAnalyzer::contradiction($afterOverride, Resolution::OUTCOME_FAVORABLE));
    }

    public function testAnAgreedOutcomeIsNotFlagged(): void
    {
        $resolution = $this->resolution('El Consejo estima la reclamación y ordena entregar todo.', Resolution::OUTCOME_FAVORABLE);

        self::assertNull(ResolutionAnalyzer::contradiction($resolution, Resolution::OUTCOME_FAVORABLE));
    }

    /**
     * `partial` itself can never be overstating a partial outcome — re-asking would be noise, and
     * the corpus has 1,488 rows the model correctly promoted to partial.
     */
    public function testAPartialLabelIsNeverChallenged(): void
    {
        $resolution = $this->resolution('El Consejo estima parcialmente la reclamación.', Resolution::OUTCOME_PARTIAL);

        self::assertNull(ResolutionAnalyzer::contradiction($resolution, Resolution::OUTCOME_PARTIAL));
    }
}
