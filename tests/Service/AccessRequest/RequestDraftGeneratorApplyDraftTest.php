<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Service\AccessRequest\RequestDraftGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure draft-normalisation rules of {@see RequestDraftGenerator::applyDraft},
 * shared with the streaming chat controller. applyDraft touches no collaborators,
 * so the instance is built without the constructor.
 */
final class RequestDraftGeneratorApplyDraftTest extends TestCase
{
    private function generator(): RequestDraftGenerator
    {
        return (new \ReflectionClass(RequestDraftGenerator::class))->newInstanceWithoutConstructor();
    }

    public function testRegChannelFillsExponeSolicitaAndMirrorsDescription(): void
    {
        $body = (new PublicBody())->setName('Consejería de Salud');
        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        $ar->setRegDestination(new RegDestination($body, 'A01', 'Unidad'));

        $result = $this->generator()->applyDraft($ar, [
            'title' => 'Datos de listas de espera',
            'expone' => '<p>Contexto de la solicitud</p>',
            'solicita' => 'Solicito el número de pacientes en lista de espera',
            'body_text' => 'ignored on REG channel',
        ]);

        $this->assertSame('Datos de listas de espera', $ar->getTitle());
        // HTML stripped.
        $this->assertSame('Contexto de la solicitud', $ar->getExpone());
        $this->assertSame('Solicito el número de pacientes en lista de espera', $ar->getSolicita());
        // Description mirrors EXPONE/SOLICITA.
        $this->assertStringContainsString('EXPONE:', $ar->getDescription());
        $this->assertStringContainsString('SOLICITA:', $ar->getDescription());
        $this->assertSame(['title', 'expone', 'solicita'], array_keys($result));
    }

    public function testPortalChannelFillsDescriptionOnly(): void
    {
        $body = (new PublicBody())->setName('Ministerio de Hacienda');
        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        // No RegDestination → portal/email channel.

        $result = $this->generator()->applyDraft($ar, [
            'title' => 'Presupuesto 2025',
            'body_text' => '<b>Solicito el desglose presupuestario</b>',
        ]);

        $this->assertSame('Presupuesto 2025', $ar->getTitle());
        $this->assertSame('Solicito el desglose presupuestario', $ar->getDescription());
        $this->assertNull($ar->getExpone());
        $this->assertNull($ar->getSolicita());
        $this->assertSame(['title', 'body_text'], array_keys($result));
    }

    public function testTruncatesTitleAndBodiesToChannelLimits(): void
    {
        $body = (new PublicBody())->setName('Consejería');
        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        $ar->setRegDestination(new RegDestination($body, 'A01', 'Unidad'));

        $this->generator()->applyDraft($ar, [
            'title' => str_repeat('t', 400),
            'expone' => str_repeat('e', 5000),
            'solicita' => str_repeat('s', 5000),
        ]);

        $this->assertSame(255, mb_strlen($ar->getTitle()));
        $this->assertSame(4000, mb_strlen((string) $ar->getExpone()));
        $this->assertSame(4000, mb_strlen((string) $ar->getSolicita()));
    }
}
