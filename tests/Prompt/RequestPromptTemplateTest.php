<?php

declare(strict_types=1);

namespace App\Tests\Prompt;

use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class RequestPromptTemplateTest extends TestCase
{
    private function store(): PromptStore
    {
        // projectDir real del repo: tests/Prompt → raíz = dirname(__DIR__, 2)
        return new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 2)),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''), // sin configurar → bundled
            new NullLogger(),
        );
    }

    public function testBundledTemplateCompilesWithAllPlaceholders(): void
    {
        $compiled = $this->store()->compile('pideinfo-request-generate-request-chat', [
            'organism' => 'Ayuntamiento de Madrid',
            'applicable_law_name' => 'Ley 19/2013',
            'applicable_law_code' => 'LTAIPBG',
            'deadline' => '1 mes (silencio negativo)',
            'channel_block' => '## Canal de prueba',
            'similar_resolutions' => 'Sin resoluciones.',
        ]);

        // Viene del bundled (Langfuse sin configurar) → sin versión.
        $this->assertNull($compiled->version);
        $this->assertSame('pideinfo-request-generate-request-chat', $compiled->name);
        // Todos los placeholders sustituidos: no quedan {{...}} residuales.
        $this->assertStringNotContainsString('{{', $compiled->text);
        // Valores inyectados presentes.
        $this->assertStringContainsString('Ayuntamiento de Madrid', $compiled->text);
        $this->assertStringContainsString('## Canal de prueba', $compiled->text);
        // Contenido de dominio esperado.
        $this->assertStringContainsString('solicitud de acceso a información pública', $compiled->text);
        $this->assertStringContainsString('Cita siempre la ley aplicable', $compiled->text);
    }
}
