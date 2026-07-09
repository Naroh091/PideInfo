<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\DocumentAgent;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Service\AI\DocumentAgent\CaseDocumentInventoryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Inventario del expediente que se inyecta SIEMPRE en el análisis agéntico.
 * Es la pieza que permite desambiguar solicitud vs acuse (¿ya hay una
 * solicitud registrada?) y dar contexto de los demás documentos.
 */
final class CaseDocumentInventoryBuilderTest extends TestCase
{
    private function doc(DocumentType $type, string $filename, ?string $summary = null): Document
    {
        $doc = new Document();
        $doc->setType($type);
        $doc->setOriginalFilename($filename);
        if ($summary !== null) {
            $doc->setExtractedText($summary);
        }

        return $doc;
    }

    public function testExcludesTheDocumentUnderAnalysis(): void
    {
        $request = new AccessRequest();
        $current = $this->doc(DocumentType::Unprocessed, 'nuevo.pdf');
        $other = $this->doc(DocumentType::Request, 'solicitud.pdf', 'Solicitud de contratos');
        $request->addDocument($current);
        $request->addDocument($other);

        $inventory = (new CaseDocumentInventoryBuilder())->build($request, $current);

        $this->assertStringNotContainsString('nuevo.pdf', $inventory);
        $this->assertStringContainsString('solicitud.pdf', $inventory);
    }

    public function testFlagsWhetherARequestDocumentAlreadyExists(): void
    {
        $request = new AccessRequest();
        $current = $this->doc(DocumentType::Unprocessed, 'nuevo.pdf');
        $request->addDocument($current);
        $request->addDocument($this->doc(DocumentType::Request, 'solicitud.pdf'));

        $builder = new CaseDocumentInventoryBuilder();

        $this->assertTrue($builder->hasRequestDocument($request, $current));
        $this->assertStringContainsString('SÍ existe ya un documento de tipo solicitud', $builder->build($request, $current));
    }

    public function testSaysSoWhenNoRequestDocumentExists(): void
    {
        $request = new AccessRequest();
        $current = $this->doc(DocumentType::Unprocessed, 'nuevo.pdf');
        $request->addDocument($current);
        $request->addDocument($this->doc(DocumentType::Receipt, 'acuse.pdf'));

        $builder = new CaseDocumentInventoryBuilder();

        $this->assertFalse($builder->hasRequestDocument($request, $current));
        $this->assertStringContainsString('NO existe todavía un documento de tipo solicitud', $builder->build($request, $current));
    }

    public function testTruncatesSummariesAndCapsDocumentCount(): void
    {
        $request = new AccessRequest();
        $current = $this->doc(DocumentType::Unprocessed, 'nuevo.pdf');
        $request->addDocument($current);
        for ($i = 1; $i <= 35; $i++) {
            $request->addDocument($this->doc(DocumentType::Other, "doc{$i}.pdf", str_repeat('x', 500)));
        }

        $inventory = (new CaseDocumentInventoryBuilder())->build($request, $current);

        $this->assertStringNotContainsString(str_repeat('x', 300), $inventory);
        $this->assertStringContainsString('5 documentos más no listados', $inventory);
    }

    public function testIncludesOriginFromPreviousAnalysis(): void
    {
        $request = new AccessRequest();
        $current = $this->doc(DocumentType::Unprocessed, 'nuevo.pdf');
        $alegaciones = $this->doc(DocumentType::Alegaciones, 'alegaciones.pdf', 'Alegaciones de la APV');
        $alegaciones->setAiMetadata(['origin' => 'administracion']);
        $request->addDocument($current);
        $request->addDocument($alegaciones);

        $inventory = (new CaseDocumentInventoryBuilder())->build($request, $current);

        $this->assertStringContainsString('origen: administracion', $inventory);
    }
}
