<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Enum\DocumentType;
use App\Service\AI\DocumentAnalysisNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Normalización única del resultado del análisis (agéntico y one-shot):
 * mapeo del tipo, overrides por flags, plazos de audiencia y los nuevos
 * cross-checks de origen/fase/REG/subdocumentos.
 */
final class DocumentAnalysisNormalizerTest extends TestCase
{
    private function normalize(array $data, array $context = []): array
    {
        return (new DocumentAnalysisNormalizer())->normalize($data, $context);
    }

    // ── Comportamiento existente (migrado de DocumentAnalyzerNormalizeTest) ──

    public function testNormalizesHearingFieldsForAudienciaDocument(): void
    {
        $result = $this->normalize([
            'documentType' => 'audiencia',
            'hearing_days' => '10',
            'hearing_days_type' => 'business',
        ]);

        $this->assertSame(DocumentType::Audiencia, $result['documentType']);
        $this->assertSame(10, $result['hearing_days']);
        $this->assertSame('business', $result['hearing_days_type']);
    }

    public function testHearingDaysTypeDefaultsToBusinessWhenMissingOrInvalid(): void
    {
        $missing = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15]);
        $invalid = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15, 'hearing_days_type' => 'lunar']);

        $this->assertSame('business', $missing['hearing_days_type']);
        $this->assertSame('business', $invalid['hearing_days_type']);
    }

    public function testHearingDaysNullWhenAbsentOrNotPositive(): void
    {
        $absent = $this->normalize(['documentType' => 'audiencia']);
        $zero = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 0]);
        $garbage = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 'muchos']);

        $this->assertNull($absent['hearing_days']);
        $this->assertNull($zero['hearing_days']);
        $this->assertNull($garbage['hearing_days']);
    }

    public function testOutcomeLabelsMapToResponseWithStatusHint(): void
    {
        $result = $this->normalize(['documentType' => 'inadmitida']);

        $this->assertSame(DocumentType::Response, $result['documentType']);
        $this->assertNotNull($result['accessRequestStatus']);
    }

    public function testFlagOverridesUpgradeOtherType(): void
    {
        $redirection = $this->normalize(['documentType' => 'otro', 'isRedirection' => true]);
        $this->assertSame(DocumentType::Redirection, $redirection['documentType']);
    }

    // ── Origen ──

    public function testOriginIsWhitelisted(): void
    {
        $valid = $this->normalize(['documentType' => 'resolucion', 'origin' => 'administracion']);
        $invalid = $this->normalize(['documentType' => 'resolucion', 'origin' => 'marciano']);
        $absent = $this->normalize(['documentType' => 'resolucion']);

        $this->assertSame('administracion', $valid['origin']);
        $this->assertNull($invalid['origin']);
        $this->assertNull($absent['origin']);
    }

    public function testCitizenAlegacionesBecomeAlegationResponse(): void
    {
        $result = $this->normalize(['documentType' => 'alegaciones', 'origin' => 'ciudadano']);

        $this->assertSame(DocumentType::AlegationResponse, $result['documentType']);
        $this->assertTrue($result['originCrossCheckApplied']);
    }

    public function testAdministrationAlegacionesStayAlegaciones(): void
    {
        $result = $this->normalize(['documentType' => 'alegaciones', 'origin' => 'administracion']);

        $this->assertSame(DocumentType::Alegaciones, $result['documentType']);
        $this->assertArrayNotHasKey('originCrossCheckApplied', $result);
    }

    // ── Fase ──

    public function testPhaseIsDerivedFromTypeWhenMissing(): void
    {
        $request = $this->normalize(['documentType' => 'solicitud']);
        $complaint = $this->normalize(['documentType' => 'reclamacion']);
        $court = $this->normalize(['documentType' => 'sentencia']);

        $this->assertSame('solicitud', $request['phase']);
        $this->assertSame('reclamacion', $complaint['phase']);
        $this->assertSame('judicial', $court['phase']);
    }

    public function testTypeWinsOverIncoherentPhase(): void
    {
        $result = $this->normalize(['documentType' => 'sentencia', 'phase' => 'solicitud']);

        $this->assertSame('judicial', $result['phase']);
    }

    // ── Cross-check REG (justificante que contiene la solicitud) ──

    public function testRegReceiptDowngradesToReceiptWhenRequestAlreadyExists(): void
    {
        $result = $this->normalize(
            ['documentType' => 'solicitud', 'isRegistrationReceipt' => true],
            ['hasRequestDocument' => true],
        );

        $this->assertSame(DocumentType::Receipt, $result['documentType']);
    }

    public function testRegReceiptStaysRequestWhenNoRequestDocumentYet(): void
    {
        $result = $this->normalize(
            ['documentType' => 'solicitud', 'isRegistrationReceipt' => true],
            ['hasRequestDocument' => false],
        );

        $this->assertSame(DocumentType::Request, $result['documentType']);
    }

    // ── Subdocumentos / compuestos ──

    public function testMalformedSubdocumentsAreFiltered(): void
    {
        $result = $this->normalize([
            'documentType' => 'alegaciones',
            'isComposite' => true,
            'subdocuments' => [
                ['pages' => '1-2', 'type' => 'requerimiento', 'description' => 'Requerimiento CTBG'],
                ['pages' => 'veintidós', 'type' => 'alegaciones'],
                ['pages' => '22-25', 'type' => ''],
                'no soy un objeto',
                ['pages' => '22-25', 'type' => 'alegaciones'],
            ],
        ]);

        $this->assertCount(2, $result['subdocuments']);
        $this->assertSame('1-2', $result['subdocuments'][0]['pages']);
        $this->assertSame('22-25', $result['subdocuments'][1]['pages']);
    }

    public function testIsCompositeCoherentWithSubdocuments(): void
    {
        $result = $this->normalize([
            'documentType' => 'alegaciones',
            'isComposite' => false,
            'subdocuments' => [
                ['pages' => '1-2', 'type' => 'requerimiento'],
                ['pages' => '3-25', 'type' => 'alegaciones'],
            ],
        ]);

        $this->assertTrue($result['isComposite']);
    }

    // ── Judicial ──

    public function testCourtOutcomeOnlySurvivesOnRulings(): void
    {
        $ruling = $this->normalize(['documentType' => 'sentencia', 'courtOutcome' => 'estimatorio']);
        $appeal = $this->normalize(['documentType' => 'recurso_contencioso', 'courtOutcome' => 'estimatorio']);
        $garbage = $this->normalize(['documentType' => 'sentencia', 'courtOutcome' => 'regular']);

        $this->assertSame('estimatorio', $ruling['courtOutcome']);
        $this->assertNull($appeal['courtOutcome']);
        $this->assertNull($garbage['courtOutcome']);
    }

    // ── matchedRequestId / publicBodyType ──

    public function testMatchedRequestIdMustBeUuid(): void
    {
        $valid = $this->normalize(['documentType' => 'solicitud', 'matchedRequestId' => '0197b3a0-1111-7222-8333-444455556666']);
        $invalid = $this->normalize(['documentType' => 'solicitud', 'matchedRequestId' => 'DROP TABLE']);

        $this->assertSame('0197b3a0-1111-7222-8333-444455556666', $valid['matchedRequestId']);
        $this->assertNull($invalid['matchedRequestId']);
    }

    public function testPublicBodyTypeIsWhitelisted(): void
    {
        $valid = $this->normalize(['documentType' => 'solicitud', 'publicBodyType' => 'ayuntamiento']);
        $invalid = $this->normalize(['documentType' => 'solicitud', 'publicBodyType' => 'club de fútbol']);

        $this->assertSame('ayuntamiento', $valid['publicBodyType']);
        $this->assertNull($invalid['publicBodyType']);
    }
}
