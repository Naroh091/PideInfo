<?php

declare(strict_types=1);

namespace App\Tests\Service\Llm;

use App\Prompt\CompiledPrompt;
use App\Service\AI\Llm\ChatRequest;
use PHPUnit\Framework\TestCase;

final class ChatRequestTest extends TestCase
{
    public function testPlainStringSystemPromptHasNoPromptRef(): void
    {
        $req = new ChatRequest(systemPrompt: 'Eres un asistente.');

        $this->assertSame('Eres un asistente.', $req->systemPrompt);
        $this->assertNull($req->promptRef);
    }

    public function testCompiledPromptAsSystemPromptCarriesTheReference(): void
    {
        $compiled = new CompiledPrompt(text: 'Eres un asistente.', name: 'pideinfo-test-prompt', version: 4);

        $req = new ChatRequest(systemPrompt: $compiled);

        $this->assertSame('Eres un asistente.', $req->systemPrompt);
        $this->assertSame($compiled, $req->promptRef);
        $this->assertSame('pideinfo-test-prompt', $req->promptRef->name);
        $this->assertSame(4, $req->promptRef->version);
    }

    public function testExplicitPromptRefWinsWhenPromptIsNotTheSystemPrompt(): void
    {
        // DocumentAnalyzer-style call: the compiled prompt travels in userParts,
        // systemPrompt is empty, and the reference is passed explicitly.
        $compiled = new CompiledPrompt(text: 'Analiza el documento.', name: 'pideinfo-document-analyze-single', version: 2);

        $req = new ChatRequest(systemPrompt: '', promptRef: $compiled);

        $this->assertSame('', $req->systemPrompt);
        $this->assertSame($compiled, $req->promptRef);
    }

    public function testCompiledPromptIsStringable(): void
    {
        $compiled = new CompiledPrompt(text: 'Hola', name: 'pideinfo-test-prompt');

        $this->assertSame('Hola', (string) $compiled);
        $this->assertNull($compiled->version);
    }
}
