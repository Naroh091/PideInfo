<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Regla de refinamiento del tipo preasignado por el veredicto de la IA.
 * Regresión: un documento preasignado/previamente clasificado como Alegaciones
 * nunca llegaba al caso Audiencia al reprocesar, así que el HearingProcess no
 * se creaba aunque la IA devolviera audiencia + hearing_days.
 */
final class DocumentTypeRefinementTest extends TestCase
{
    public function testAiCanRefineAlegacionesToAudiencia(): void
    {
        $this->assertTrue(DocumentType::aiRefinesPreassigned(DocumentType::Alegaciones, DocumentType::Audiencia));
    }

    public function testAiCanRefineGenericComplaintToAudiencia(): void
    {
        // Complaint es el fallback del mapeo del agente cuando ni fase ni título matchean.
        $this->assertTrue(DocumentType::aiRefinesPreassigned(DocumentType::Complaint, DocumentType::Audiencia));
    }

    public function testPreassignedComplaintResolutionIsNeverOverridden(): void
    {
        // El caso documentado: la IA no distingue una resolución de reclamación
        // de una respuesta normal — ahí el tipo preasignado debe ganar siempre.
        $this->assertFalse(DocumentType::aiRefinesPreassigned(DocumentType::ComplaintResolution, DocumentType::Response));
        $this->assertFalse(DocumentType::aiRefinesPreassigned(DocumentType::ComplaintResolution, DocumentType::Audiencia));
    }

    public function testAudienciaPreassignmentIsNotDowngraded(): void
    {
        // Dirección inversa: si el agente ya lo marcó como Audiencia, la IA no lo degrada.
        $this->assertFalse(DocumentType::aiRefinesPreassigned(DocumentType::Audiencia, DocumentType::Alegaciones));
    }

    public function testAiCanRefineAlegacionesToInterAdminCommunication(): void
    {
        // La fase "alegaciones" del CTBG trae también los justificantes REGAGE y
        // requerimientos Consejo↔Administración; el mapeo por fase los preasigna
        // Alegaciones y solo el contenido permite distinguirlos.
        $this->assertTrue(DocumentType::aiRefinesPreassigned(DocumentType::Alegaciones, DocumentType::ComplaintInterAdmin));
        $this->assertTrue(DocumentType::aiRefinesPreassigned(DocumentType::Complaint, DocumentType::ComplaintInterAdmin));
    }

    public function testAiCanRefineAlegacionesToAlegationResponse(): void
    {
        // Cross-check de origen: unas "alegaciones" del ciudadano son su
        // respuesta a las de la Administración.
        $this->assertTrue(DocumentType::aiRefinesPreassigned(DocumentType::Alegaciones, DocumentType::AlegationResponse));
    }

    public function testInterAdminRefinementDoesNotApplyToOtherPreassignments(): void
    {
        $this->assertFalse(DocumentType::aiRefinesPreassigned(DocumentType::ComplaintResolution, DocumentType::ComplaintInterAdmin));
        $this->assertFalse(DocumentType::aiRefinesPreassigned(DocumentType::Audiencia, DocumentType::ComplaintInterAdmin));
    }
}
