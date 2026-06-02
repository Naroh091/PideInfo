<?php

declare(strict_types=1);

namespace App\Tests\Prompt;

use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PromptStoreTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/prompt-store-test-' . uniqid();
        mkdir($this->projectDir . '/config/prompts/test', 0777, true);
        file_put_contents(
            $this->projectDir . '/config/prompts/test/saludo.md',
            'Hola {{name}}, soy el prompt local.',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/config/prompts/test/saludo.md');
        @rmdir($this->projectDir . '/config/prompts/test');
        @rmdir($this->projectDir . '/config/prompts');
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    public function testCompileFromLangfuseCarriesNameAndVersion(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse((string) json_encode([
                'prompt' => 'Hola {{name}}, soy el prompt de Langfuse.',
                'version' => 7,
            ])),
        ]);

        $store = new PromptStore(
            new BundledPromptLoader($this->projectDir),
            new LangfuseAdminClient($httpClient, 'https://langfuse.test', 'pk-test', 'sk-test'),
            new NullLogger(),
            'production',
        );

        $compiled = $store->compile('pideinfo-test-saludo', ['name' => 'David']);

        $this->assertSame('Hola David, soy el prompt de Langfuse.', $compiled->text);
        $this->assertSame('pideinfo-test-saludo', $compiled->name);
        $this->assertSame(7, $compiled->version);
    }

    public function testCompileFallsBackToBundledWithoutVersion(): void
    {
        // Unconfigured Langfuse client (empty keys) → bundled template, no version.
        $store = new PromptStore(
            new BundledPromptLoader($this->projectDir),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        );

        $compiled = $store->compile('pideinfo-test-saludo', ['name' => 'David']);

        $this->assertSame('Hola David, soy el prompt local.', $compiled->text);
        $this->assertSame('pideinfo-test-saludo', $compiled->name);
        $this->assertNull($compiled->version);
    }

    public function testCompileFallsBackToBundledOnLangfuse404(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"message": "not found"}', ['http_code' => 404]),
        ]);

        $store = new PromptStore(
            new BundledPromptLoader($this->projectDir),
            new LangfuseAdminClient($httpClient, 'https://langfuse.test', 'pk-test', 'sk-test'),
            new NullLogger(),
            'production',
        );

        $compiled = $store->compile('pideinfo-test-saludo');

        $this->assertSame('Hola {{name}}, soy el prompt local.', $compiled->text);
        $this->assertNull($compiled->version);
    }
}
