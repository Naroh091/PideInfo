<?php

declare(strict_types=1);

namespace App\Tests\Service\Complaint;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Prompt\BundledPromptLoader;
use App\Prompt\CompiledPrompt;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptCatalog;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ChatResult;
use App\Service\AI\Llm\LlmClient;
use App\Service\Complaint\ComplaintGenerator;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(ComplaintGenerator::class)]
final class ComplaintAmpliationTest extends TestCase
{
    private ComplaintGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = (new \ReflectionClass(ComplaintGenerator::class))->newInstanceWithoutConstructor();
    }

    // ------------------------------------------------------------------
    // extractCitedResolutions / extractCitedCriteria
    // ------------------------------------------------------------------

    public function testExtractCitedResolutionsFindsLiteralReferences(): void
    {
        $resolutions = [
            ['reference' => 'R/0123/2023', 'date' => '01/02/2023', 'summary' => 'Causa restrictiva'],
            ['reference' => 'R/0456/2024', 'date' => null, 'summary' => 'Otro caso'],
        ];

        $cited = $this->generator->extractCitedResolutions(
            '<p>como estableció el Consejo en su Resolución R/0123/2023, la causa es restrictiva</p>',
            $resolutions,
        );

        $this->assertCount(1, $cited);
        $this->assertSame('R/0123/2023', $cited[0]->reference);
    }

    public function testExtractCitedResolutionsIgnoresReferencesHiddenInAttributes(): void
    {
        $resolutions = [
            ['reference' => 'R/0456/2024', 'date' => null, 'summary' => 'Otro caso'],
        ];

        $cited = $this->generator->extractCitedResolutions(
            '<p data-ref="R/0456/2024">texto sin citas literales</p>',
            $resolutions,
        );

        $this->assertSame([], $cited);
    }

    public function testExtractCitedCriteriaRequiresLiteralFormula(): void
    {
        $criteria = [
            ['criterion' => 'CI/006/2015', 'year' => 2015],
        ];

        // Bare identifier is too lax and must not count.
        $this->assertSame(
            [],
            $this->generator->extractCitedCriteria('<p>según CI/006/2015 procede estimar</p>', $criteria),
        );

        $cited = $this->generator->extractCitedCriteria(
            '<p>según el Criterio CI/006/2015 procede estimar</p>',
            $criteria,
        );

        $this->assertSame(['Criterio CI/006/2015 (2015)'], $cited);
    }

    // ------------------------------------------------------------------
    // needsCitationRetry
    // ------------------------------------------------------------------

    public function testNeedsCitationRetryOnlyWhenSourcesWereProvidedButNothingCited(): void
    {
        $resolutions = [['reference' => 'R/0123/2023']];
        $criteria = [['criterion' => 'CI/006/2015', 'year' => 2015]];

        $this->assertTrue($this->generator->needsCitationRetry($criteria, $resolutions, [], []));
        $this->assertFalse($this->generator->needsCitationRetry([], [], [], []));
        $this->assertFalse($this->generator->needsCitationRetry(
            $criteria,
            $resolutions,
            [$this->citedResolution('R/0123/2023')],
            [],
        ));
    }

    // ------------------------------------------------------------------
    // chatWithCitationRetry (private): one automatic retry, then give up gracefully
    // ------------------------------------------------------------------

    public function testCitationRetryRewritesOnceWhenFirstAttemptCitesNothing(): void
    {
        $resolutions = [['reference' => 'R/0123/2023', 'date' => null, 'summary' => 'x']];
        $requests = [];
        $this->setLlmClient(function (ChatRequest $req) use (&$requests): ChatResult {
            $requests[] = $req;
            return count($requests) === 1
                ? new ChatResult('<p>argumentación sin citas</p>')
                : new ChatResult('<p>apoyado en la Resolución R/0123/2023 del Consejo</p>');
        });

        $result = $this->callChatWithCitationRetry([], $resolutions);

        $this->assertCount(2, $requests);
        $this->assertStringContainsString(
            ComplaintGenerator::CITATION_RETRY_APPEND,
            $requests[1]->systemPrompt,
        );
        $this->assertStringContainsString('R/0123/2023', $result['content']);
        $this->assertCount(1, $result['citedResolutions']);
    }

    public function testNoRetryWhenFirstAttemptAlreadyCites(): void
    {
        $resolutions = [['reference' => 'R/0123/2023', 'date' => null, 'summary' => 'x']];
        $calls = 0;
        $this->setLlmClient(function () use (&$calls): ChatResult {
            $calls++;
            return new ChatResult('<p>apoyado en la Resolución R/0123/2023 del Consejo</p>');
        });

        $result = $this->callChatWithCitationRetry([], $resolutions);

        $this->assertSame(1, $calls);
        $this->assertCount(1, $result['citedResolutions']);
    }

    public function testRetryReturnsSecondAttemptEvenWhenItStillCitesNothing(): void
    {
        $resolutions = [['reference' => 'R/0123/2023', 'date' => null, 'summary' => 'x']];
        $calls = 0;
        $this->setLlmClient(function () use (&$calls): ChatResult {
            $calls++;
            return new ChatResult('<p>segunda versión, tampoco cita</p>');
        });

        $result = $this->callChatWithCitationRetry([], $resolutions);

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('segunda versión', $result['content']);
        $this->assertSame([], $result['citedResolutions']);
    }

    // ------------------------------------------------------------------
    // sanitizeHtmlResponse (private): whitelist + context-only links
    // ------------------------------------------------------------------

    public function testSanitizeKeepsWhitelistAndDropsInventedLinks(): void
    {
        $html = '<div class="x"><p>El plazo venció el <strong>25/05/2026</strong></p>'
            . '<script>alert(1)</script>'
            . '<p>Ver <a href="https://real.example/doc" onclick="evil()">documento</a> y '
            . '<a href="https://inventada.example/x">inventado</a> y '
            . '<a href="javascript:alert(1)">js</a></p></div>';

        $clean = $this->callSanitize($html, ['https://real.example/doc']);

        $this->assertStringNotContainsString('<div', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('<strong>25/05/2026</strong>', $clean);
        $this->assertStringContainsString('<a href="https://real.example/doc">documento</a>', $clean);
        $this->assertStringNotContainsString('inventada.example', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    // ------------------------------------------------------------------
    // canGenerateAmpliation
    // ------------------------------------------------------------------

    public function testCanGenerateAmpliationRequiresReclaimedComplaintAndLateResponse(): void
    {
        $filedAt = new \DateTimeImmutable('2026-05-01 10:00:00');

        // No complaint at all.
        $this->assertFalse($this->generator->canGenerateAmpliation(new AccessRequest()));

        // Complaint not reclaimed.
        $ar = $this->requestWithComplaint('complaint_granted', $filedAt, [
            $this->responseDocument(new \DateTimeImmutable('2026-06-01')),
        ]);
        $this->assertFalse($this->generator->canGenerateAmpliation($ar));

        // Reclaimed but the only response predates the complaint.
        $ar = $this->requestWithComplaint(AccessRequestComplaint::STATUS_RECLAIMED, $filedAt, [
            $this->responseDocument(new \DateTimeImmutable('2026-04-01')),
        ]);
        $this->assertFalse($this->generator->canGenerateAmpliation($ar));

        // Reclaimed with a post-complaint response: ampliation applies.
        $ar = $this->requestWithComplaint(AccessRequestComplaint::STATUS_RECLAIMED, $filedAt, [
            $this->responseDocument(new \DateTimeImmutable('2026-04-01')),
            $this->responseDocument(new \DateTimeImmutable('2026-06-15')),
        ]);
        $this->assertTrue($this->generator->canGenerateAmpliation($ar));
    }

    // ------------------------------------------------------------------
    // saveAmpliation persists a ComplaintExtension document
    // ------------------------------------------------------------------

    public function testSaveAmpliationPersistsComplaintExtension(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('write');
        $this->setProperty('entityManager', $entityManager);
        $this->setProperty('documentsStorage', $storage);

        $ar = new AccessRequest();
        $ar->setUser(new User());
        $draft = new ComplaintDraft(
            content: '<p>Ampliación con <strong>15/06/2026</strong></p>',
            transparencyCouncil: 'CTBG',
            applicableLaw: 'Ley 19/2013',
        );

        $document = $this->generator->saveAmpliation($ar, $draft);

        $this->assertSame(DocumentType::ComplaintExtension, $document->getType());
        $this->assertSame('text/html', $document->getMimeType());
    }

    // ------------------------------------------------------------------
    // New prompts: catalogued, bundled and compilable with the code's variables
    // ------------------------------------------------------------------

    public function testAmpliationPromptsAreCataloguedAndCompile(): void
    {
        $names = array_column(PromptCatalog::all(), 'name');
        $this->assertContains('pideinfo-complaint-generate-ampliation', $names);
        $this->assertContains('pideinfo-complaint-generate-ampliation-chat', $names);

        $store = new PromptStore(
            new BundledPromptLoader($this->projectDir()),
            new LangfuseAdminClient($this->createMock(HttpClientInterface::class), '', '', ''),
            new NullLogger(),
        );

        $vars = [
            'transparency_council' => 'Consejo de Transparencia y Buen Gobierno',
            'request_title' => 'Estadísticas de nacionalidad',
            'request_description' => 'Descripción',
            'public_body_name' => 'Ministerio',
            'applicable_law_name' => 'Ley 19/2013',
            'complaint_filed_at' => '01/05/2026',
            'complaint_external_id' => ' con nº CTBG-1',
            'post_response_text' => 'Resolución tardía de inadmisión',
            'documents_block' => '',
            'criteria_text' => 'Criterio CI/006/2015',
            'resolutions_text' => 'Resolución R/0123/2023',
        ];

        foreach (['pideinfo-complaint-generate-ampliation', 'pideinfo-complaint-generate-ampliation-chat'] as $name) {
            $compiled = $store->compile($name, $vars);
            $this->assertStringNotContainsString('{{', $compiled->text, "Unsubstituted placeholder in {$name}");
            $this->assertStringContainsString('AMPLIACIÓN', $compiled->text);
        }
        $this->assertStringContainsString(
            'NO es una respuesta a alegaciones',
            $store->compile('pideinfo-complaint-generate-ampliation', $vars)->text,
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function requestWithComplaint(string $status, \DateTimeImmutable $filedAt, array $documents): AccessRequest
    {
        $complaint = new AccessRequestComplaint();
        $complaint->setStatus($status);
        $complaint->setFiledAt($filedAt);

        $ar = new AccessRequest();
        $ar->setComplaint($complaint);
        foreach ($documents as $document) {
            $ar->addDocument($document);
        }

        return $ar;
    }

    private function responseDocument(\DateTimeImmutable $date): Document
    {
        $document = new Document();
        $document->setType(DocumentType::Response);
        $document->setDocumentDate($date);

        return $document;
    }

    private function citedResolution(string $reference): \App\DTO\CitedResolution
    {
        return new \App\DTO\CitedResolution(reference: $reference, date: null, excerpt: 'x');
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<int, array<string, mixed>> $resolutions
     * @return array{content: string, citedResolutions: array, citedCriteria: array}
     */
    private function callChatWithCitationRetry(array $criteria, array $resolutions): array
    {
        $method = new \ReflectionMethod(ComplaintGenerator::class, 'chatWithCitationRetry');

        return $method->invoke(
            $this->generator,
            'contexto https://real.example/doc',
            [],
            new CompiledPrompt('contexto', 'pideinfo-complaint-generate-ampliation'),
            'test',
            false,
            $criteria,
            $resolutions,
        );
    }

    private function callSanitize(string $html, array $allowedUrls): string
    {
        $method = new \ReflectionMethod(ComplaintGenerator::class, 'sanitizeHtmlResponse');

        return $method->invoke($this->generator, $html, $allowedUrls);
    }

    private function setLlmClient(callable $chat): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chat')->willReturnCallback($chat);
        $this->setProperty('llmClient', $llm);
    }

    private function setProperty(string $name, object $value): void
    {
        $property = new \ReflectionProperty(ComplaintGenerator::class, $name);
        $property->setValue($this->generator, $value);
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
