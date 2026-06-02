<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentDisplayFilenameTest extends TestCase
{
    public function testPrependsTypeLabel(): void
    {
        $doc = $this->doc('informe.pdf', DocumentType::Response);

        $this->assertSame('Respuesta - informe.pdf', $doc->getDisplayFilename());
    }

    public function testDoesNotDuplicateSameTypePrefix(): void
    {
        $doc = $this->doc('Alegaciones - escrito.pdf', DocumentType::Alegaciones);

        $this->assertSame('Alegaciones - escrito.pdf', $doc->getDisplayFilename());
    }

    public function testReplacesStalePrefixWhenTypeChanges(): void
    {
        // Regresión: documento reclasificado al reprocesar (Alegaciones → Audiencia).
        // El prefijo antiguo debe sustituirse, no apilarse.
        $doc = $this->doc('Alegaciones - 20260601_Notificación_Trámite de audiencia.pdf', DocumentType::Audiencia);

        $this->assertSame(
            'Trámite de audiencia - 20260601_Notificación_Trámite de audiencia.pdf',
            $doc->getDisplayFilename(),
        );
    }

    public function testUnprocessedAndOtherKeepOriginalName(): void
    {
        $this->assertSame(
            'doc.pdf',
            $this->doc('doc.pdf', DocumentType::Unprocessed)->getDisplayFilename(),
        );
        $this->assertSame(
            'doc.pdf',
            $this->doc('doc.pdf', DocumentType::Other)->getDisplayFilename(),
        );
    }

    private function doc(string $filename, DocumentType $type): Document
    {
        return (new Document())
            ->setOriginalFilename($filename)
            ->setType($type);
    }
}
