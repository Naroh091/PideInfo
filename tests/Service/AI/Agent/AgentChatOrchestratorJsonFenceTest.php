<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Service\AI\Agent\AgentChatOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the ```json fence stripping applied before every
 * json_decode of a model decision (tool-loop fast path, final call, nudge
 * retry and trace preview).
 */
class AgentChatOrchestratorJsonFenceTest extends TestCase
{
    public function testPlainJsonIsUntouched(): void
    {
        $json = '{"conversational_reply":"<p>Hola</p>","action":"reply"}';

        $this->assertSame($json, AgentChatOrchestrator::stripJsonFences($json));
        $this->assertSame($json, AgentChatOrchestrator::stripJsonFences("  {$json}  \n"));
    }

    public function testJsonFenceIsStripped(): void
    {
        $json = '{"conversational_reply":"<p>Hola</p>","action":"reply"}';

        $this->assertSame(
            $json,
            AgentChatOrchestrator::stripJsonFences("```json\n{$json}\n```"),
        );
    }

    public function testBareFenceIsStripped(): void
    {
        $json = '{"action":"reply"}';

        $this->assertSame(
            $json,
            AgentChatOrchestrator::stripJsonFences("```\n{$json}\n```"),
        );
    }

    public function testTrailingTextAfterClosingFenceIsKeptOut(): void
    {
        // Only the fenced payload is decoded; anything after the fence is dropped.
        $this->assertSame(
            '{"action":"reply"}',
            AgentChatOrchestrator::stripJsonFences("```json\n{\"action\":\"reply\"}\n```\nEspero que te sirva."),
        );
    }

    public function testUnclosedFenceKeepsInnerContent(): void
    {
        $this->assertSame(
            '{"action":"reply"}',
            AgentChatOrchestrator::stripJsonFences("```json\n{\"action\":\"reply\"}"),
        );
    }

    public function testNonJsonTextIsUntouched(): void
    {
        $text = 'Esto no es JSON, aunque mencione ``` por en medio.';

        $this->assertSame($text, AgentChatOrchestrator::stripJsonFences($text));
        $this->assertSame('', AgentChatOrchestrator::stripJsonFences(''));
        $this->assertSame('', AgentChatOrchestrator::stripJsonFences('```'));
    }

    public function testStrippedOutputDecodes(): void
    {
        $decoded = json_decode(
            AgentChatOrchestrator::stripJsonFences("```json\n{\"conversational_reply\":\"<p>Hola</p>\",\"action\":\"reply\"}\n```"),
            true,
        );

        $this->assertIsArray($decoded);
        $this->assertSame('reply', $decoded['action']);
    }
}
