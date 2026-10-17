<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Mcp\Service\DocumentContentReader;
use App\Observability\Tracer;
use App\Prompt\PromptStore;
use App\Repository\AccessRequestRepository;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Agent\Tool\ReadRequestDocumentsTool;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\Document\PdfTextExtractor;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use OpenTelemetry\SDK\Trace\TracerProvider as SdkTracerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * Latency plan (PLAN_LATENCIA.md) for `read_request_documents`:
 *
 * 1. Per-document cache (id + createdAt + type): a regeneration only analyses
 *    new documents — the second read of the same expediente spends zero LLM
 *    calls and returns the same output.
 * 2. `limit` is not part of the cache key: different limits reuse entries.
 * 3. Ranks 2-4 reuse the ingestion AI summary with zero LLM calls; a shallow
 *    document without a summary still goes through the deep path.
 * 4. Pending deep documents go out in ONE batched chatJson call (split into
 *    chunks past the char budget), with per-document fallback on bad batches.
 * 5. Several chunks are dispatched concurrently, with sequential fallback.
 * 6. The tool annotates its own span with analysed vs reused counters.
 *
 * No database, no network: repository, content reader and LLM are mocked; the
 * prompt store is the real container service (bundled fallback prompt).
 */
final class ReadRequestDocumentsToolTest extends KernelTestCase
{
    /** @var list<string> userTexts sent to chatJson, in order */
    private array $singleTexts = [];

    /** @var list<list<string>> filenames requested per chatJsonConcurrent call */
    private array $concurrentBatches = [];

    private int $singleCalls = 0;

    private int $concurrentCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->singleTexts = [];
        $this->concurrentBatches = [];
        $this->singleCalls = 0;
        $this->concurrentCalls = 0;
    }

    public function testDeepDocumentsGoOutInOneBatchAndSecondReadSpendsZeroLlmCalls(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('resolucion.pdf', DocumentType::Response, null),
            $this->doc('alegaciones.pdf', DocumentType::Alegaciones, null),
            $this->doc('acuse.pdf', DocumentType::Receipt, 'Resumen del acuse de recibo.'),
        ]);
        $texts = [
            'resolucion.pdf' => 'TEXTO_RESOLUCION sobre la denegación.',
            'alegaciones.pdf' => 'TEXTO_ALEGACIONES de la administración.',
        ];
        $harness = $this->harness($user, $request, $texts);

        // First read: one batched call for the two deep docs, none for the receipt.
        [$output1, $span1] = $this->tracedCall($harness, $request);
        self::assertSame(1, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
        self::assertStringContainsString('TEXTO_RESOLUCION', $this->singleTexts[0]);
        self::assertStringContainsString('TEXTO_ALEGACIONES', $this->singleTexts[0]);
        self::assertStringContainsString('Hecho de resolucion.pdf', $output1);
        self::assertStringContainsString('Hecho de alegaciones.pdf', $output1);
        self::assertStringContainsString('Resumen del acuse de recibo.', $output1);
        self::assertStringContainsString('resumen de ingesta', $output1);
        self::assertSame(3, $span1->getAttribute('agent.docs_total'));
        self::assertSame(2, $span1->getAttribute('agent.docs_analyzed'));
        self::assertSame(0, $span1->getAttribute('agent.docs_reused'));
        self::assertSame(1, $span1->getAttribute('agent.docs_summary'));
        self::assertSame(1, $span1->getAttribute('agent.llm_batches'));
        self::assertFalse($span1->getAttribute('agent.cache_hit'));

        // Second read: everything cached — zero LLM calls, identical output.
        [$output2, $span2] = $this->tracedCall($harness, $request);
        self::assertSame(1, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
        self::assertSame($output1, $output2);
        self::assertSame(0, $span2->getAttribute('agent.docs_analyzed'));
        self::assertSame(2, $span2->getAttribute('agent.docs_reused'));
        self::assertSame(1, $span2->getAttribute('agent.docs_summary'));
        self::assertSame(0, $span2->getAttribute('agent.llm_batches'));
        self::assertTrue($span2->getAttribute('agent.cache_hit'));

        // Different limit reuses the same per-document entries (limit is not keyed).
        $output3 = ($harness['tool'])($request->getId()->toRfc4122(), '', 2);
        self::assertSame(1, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
        self::assertStringContainsString('Hecho de resolucion.pdf', $output3);
        self::assertStringContainsString('Hecho de alegaciones.pdf', $output3);
    }

    public function testAddingADocumentOnlyAnalysesTheNewOne(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('resolucion.pdf', DocumentType::Response, null),
            $this->doc('acuse.pdf', DocumentType::Receipt, 'Resumen del acuse.'),
        ]);
        $texts = ['resolucion.pdf' => 'TEXTO_RESOLUCION original.'];
        $harness = $this->harness($user, $request, $texts);

        ($harness['tool'])($request->getId()->toRfc4122());
        self::assertSame(1, $this->singleCalls);

        // Regeneration scenario: a draft was saved as a new document.
        $nuevo = $this->doc('alegaciones.pdf', DocumentType::Alegaciones, null);
        $request->addDocument($nuevo);
        $harness['fs']->write('stored-alegaciones.pdf', 'TEXTO_ALEGACIONES nuevas.');

        $output = ($harness['tool'])($request->getId()->toRfc4122());
        self::assertSame(2, $this->singleCalls);
        self::assertStringContainsString('TEXTO_ALEGACIONES nuevas.', $this->singleTexts[1]);
        self::assertStringNotContainsString('TEXTO_RESOLUCION original.', $this->singleTexts[1]);
        self::assertStringContainsString('Hecho de resolucion.pdf', $output);
        self::assertStringContainsString('Hecho de alegaciones.pdf', $output);
        self::assertStringContainsString('Resumen del acuse.', $output);
    }

    public function testProceduralDocumentsUseIngestionSummaryWithoutLlm(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('acuse.pdf', DocumentType::Receipt, 'Resumen del acuse.'),
            $this->doc('inicio.pdf', DocumentType::ProcessingStart, 'Resumen del inicio.'),
            $this->doc('solicitud.pdf', DocumentType::Request, 'Resumen de lo solicitado.'),
            $this->doc('traslado.pdf', DocumentType::Redirection, 'Resumen del traslado.'),
        ]);
        $harness = $this->harness($user, $request, []);

        [$output, $span] = $this->tracedCall($harness, $request);

        self::assertSame(0, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
        // The content reader never ran on summary docs: their fullText cache
        // column is still empty (a read would have populated it).
        foreach ($request->getDocuments() as $doc) {
            self::assertNull($doc->getFullText(), $doc->getOriginalFilename());
        }
        foreach (['Resumen del acuse.', 'Resumen del inicio.', 'Resumen de lo solicitado.', 'Resumen del traslado.'] as $summary) {
            self::assertStringContainsString($summary, $output);
        }
        self::assertSame(4, $span->getAttribute('agent.docs_summary'));
        self::assertSame(0, $span->getAttribute('agent.docs_analyzed'));
    }

    public function testShallowDocumentWithoutSummaryFallsBackToDeepPath(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('acuse.pdf', DocumentType::Receipt, null),
        ]);
        $harness = $this->harness($user, $request, ['acuse.pdf' => 'TEXTO_ACUSE sin resumen de ingesta.']);

        $output = ($harness['tool'])($request->getId()->toRfc4122());

        self::assertSame(1, $this->singleCalls);
        self::assertStringContainsString('Hecho de acuse.pdf', $output);
    }

    public function testLargeExpedienteSplitsIntoConcurrentBatches(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('doc-a.pdf', DocumentType::Response, null),
            $this->doc('doc-b.pdf', DocumentType::Alegaciones, null),
            $this->doc('doc-c.pdf', DocumentType::Audiencia, null),
        ]);
        $texts = [
            'doc-a.pdf' => str_repeat('A', 25_000),
            'doc-b.pdf' => str_repeat('B', 25_000),
            'doc-c.pdf' => str_repeat('C', 25_000),
        ];
        $harness = $this->harness($user, $request, $texts, concurrentResponder: true);

        [$output, $span] = $this->tracedCall($harness, $request);

        // 75k chars over the ~60k budget → 2 chunks in a single concurrent round.
        self::assertSame(0, $this->singleCalls);
        self::assertSame(1, $this->concurrentCalls);
        self::assertCount(2, $this->concurrentBatches[0]);
        self::assertSame(2, $span->getAttribute('agent.llm_batches'));
        self::assertSame(3, $span->getAttribute('agent.docs_analyzed'));
        foreach (['doc-a.pdf', 'doc-b.pdf', 'doc-c.pdf'] as $name) {
            self::assertStringContainsString('Hecho de ' . $name, $output);
        }
    }

    public function testConcurrentFailureFallsBackToSequentialBatches(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('doc-a.pdf', DocumentType::Response, null),
            $this->doc('doc-b.pdf', DocumentType::Alegaciones, null),
            $this->doc('doc-c.pdf', DocumentType::Audiencia, null),
        ]);
        $texts = [
            'doc-a.pdf' => str_repeat('A', 25_000),
            'doc-b.pdf' => str_repeat('B', 25_000),
            'doc-c.pdf' => str_repeat('C', 25_000),
        ];
        $harness = $this->harness($user, $request, $texts, concurrentThrows: true);

        $output = ($harness['tool'])($request->getId()->toRfc4122());

        self::assertSame(1, $this->concurrentCalls);
        self::assertSame(2, $this->singleCalls);
        foreach (['doc-a.pdf', 'doc-b.pdf', 'doc-c.pdf'] as $name) {
            self::assertStringContainsString('Hecho de ' . $name, $output);
        }
    }

    public function testMalformedBatchFallsBackToPerDocumentCalls(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('doc-a.pdf', DocumentType::Response, null),
            $this->doc('doc-b.pdf', DocumentType::Alegaciones, null),
        ]);
        $harness = $this->harness($user, $request, [
            'doc-a.pdf' => 'TEXTO_A.',
            'doc-b.pdf' => 'TEXTO_B.',
        ], malformedBatch: true);

        $output = ($harness['tool'])($request->getId()->toRfc4122());

        // 1 unusable batch + 2 per-document retries.
        self::assertSame(3, $this->singleCalls);
        self::assertStringContainsString('Hecho de doc-a.pdf', $output);
        self::assertStringContainsString('Hecho de doc-b.pdf', $output);
    }

    public function testUnreadableDocumentSpendsNoLlmCall(): void
    {
        self::bootKernel();
        [$user, $request] = $this->requestWithDocs('owner@example.com', [
            $this->doc('resolucion.pdf', DocumentType::Response, null),
        ]);
        $harness = $this->harness($user, $request, []);

        $output1 = ($harness['tool'])($request->getId()->toRfc4122());
        self::assertSame(0, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
        self::assertStringContainsString('No se pudo extraer contenido', $output1);

        // The failure is cached (short TTL) — a repeat read still spends nothing.
        ($harness['tool'])($request->getId()->toRfc4122());
        self::assertSame(0, $this->singleCalls);
        self::assertSame(0, $this->concurrentCalls);
    }

    /**
     * @param list<Document> $docs
     * @return array{0: User, 1: AccessRequest}
     */
    private function requestWithDocs(string $ownerEmail, array $docs): array
    {
        $user = new User();
        $user->setEmail($ownerEmail);
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');

        $body = new PublicBody();
        $body->setName('Ayto Test');

        $request = new AccessRequest();
        $request->setUser($user);
        $request->setPublicBody($body);
        $request->setTitle('Título');
        $request->setDescription('Descripción.');
        $request->setStatus(AccessRequest::STATUS_PENDING);
        foreach ($docs as $doc) {
            $doc->setUploadedBy($user);
            $request->addDocument($doc);
        }

        return [$user, $request];
    }

    private function doc(string $name, DocumentType $type, ?string $summary): Document
    {
        $doc = new Document();
        $doc->setOriginalFilename($name);
        $doc->setStoredFilename('stored-' . $name);
        // text/plain keeps the real DocumentContentReader on its simplest path
        // (no PDF parsing) — the harness seeds file bytes per test.
        $doc->setMimeType('text/plain');
        $doc->setFileSize(100);
        $doc->setType($type);
        $doc->setExtractedText($summary);

        return $doc;
    }

    /**
     * Builds the tool with a mocked repository/LLM/security, the REAL
     * DocumentContentReader over an in-memory filesystem seeded with $texts,
     * an ArrayAdapter cache and an SDK-backed tracer.
     *
     * @param array<string, string> $texts filename => file bytes (missing file = unreadable)
     * @return array{tool: ReadRequestDocumentsTool, texts: array<string, string>}
     */
    private function harness(
        User $user,
        AccessRequest $request,
        array $texts,
        bool $concurrentResponder = false,
        bool $concurrentThrows = false,
        bool $malformedBatch = false,
    ): array {
        $repository = $this->createMock(AccessRequestRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (Uuid $id) => $id->toRfc4122() === $request->getId()->toRfc4122() ? $request : null,
        );

        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        foreach ($texts as $name => $bytes) {
            $fs->write('stored-' . $name, $bytes);
        }
        $harnessTexts = $texts;
        $em = $this->createMock(EntityManagerInterface::class);
        $contentReader = new DocumentContentReader(
            $fs,
            new PdfTextExtractor(),
            $em,
            new S3Client(['version' => 'latest', 'region' => 'eu-west-1']),
            'test-bucket',
        );

        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $req) use ($malformedBatch): array {
            $this->singleCalls++;
            $this->singleTexts[] = $req->userText ?? '';
            if ($malformedBatch && str_contains($req->userText ?? '', '## Documento')) {
                return ['extractions' => []];
            }

            return $this->respond($req);
        });
        $llm->method('chatJsonConcurrent')->willReturnCallback(function (array $requests) use ($concurrentResponder, $concurrentThrows): array {
            $this->concurrentCalls++;
            $this->concurrentBatches[] = array_map(
                static fn (ChatRequest $r) => self::batchFilenames($r->userText ?? ''),
                $requests,
            );
            if ($concurrentThrows) {
                throw new \RuntimeException('concurrent dispatch failed');
            }
            if (!$concurrentResponder) {
                return [];
            }

            return array_map(fn (ChatRequest $r) => $this->respond($r), $requests);
        });

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        // Any tracer works here: annotate() targets Span::getCurrent(), and the
        // tracedCall() helper activates its span globally around the call.
        $tool = new ReadRequestDocumentsTool(
            $repository,
            $contentReader,
            $llm,
            static::getContainer()->get(PromptStore::class),
            $security,
            new AgentProgress(),
            new ArrayAdapter(),
            new Tracer(new SdkTracerProvider()),
        );

        return ['tool' => $tool, 'texts' => &$harnessTexts, 'fs' => $fs];
    }

    /**
     * Runs one call inside an active SDK span, so annotate() lands on a span
     * whose attributes the test can read back.
     *
     * @param array{tool: ReadRequestDocumentsTool, texts: array<string, string>} $harness
     * @return array{0: string, 1: \OpenTelemetry\SDK\Trace\Span}
     */
    private function tracedCall(array $harness, AccessRequest $request): array
    {
        $provider = new SdkTracerProvider();
        $span = $provider->getTracer('test')->spanBuilder('agent.tool.read_request_documents')->startSpan();
        $scope = $span->activate();
        try {
            $output = ($harness['tool'])($request->getId()->toRfc4122());
        } finally {
            $scope->detach();
            $span->end();
        }

        return [$output, $span];
    }

    /**
     * Canned LLM answer: one extraction per requested filename, parsed out of
     * the request's userText (batch `## Documento N de M:` blocks or the
     * single `**Documento:**` line).
     *
     * @return array<string, mixed>
     */
    private function respond(ChatRequest $req): array
    {
        $text = $req->userText ?? '';
        if (str_contains($text, '## Documento')) {
            return ['extractions' => array_map(
                static fn (string $fn): array => self::extractionFor($fn),
                self::batchFilenames($text),
            )];
        }

        $filename = 'desconocido';
        if (preg_match('/^\*\*Documento:\*\* (.+)$/m', $text, $m) === 1) {
            $filename = trim($m[1]);
        }

        return self::extractionFor($filename);
    }

    /** @return array<string, mixed> */
    private static function extractionFor(string $filename): array
    {
        return [
            'filename' => $filename,
            'document_type' => 'respuesta_administracion',
            'key_facts' => ['Hecho de ' . $filename],
            'denial_reasons' => ['Motivo de ' . $filename],
            'relevant_dates' => ['solicitud' => '2026-01-15'],
            'useful_for_drafting' => 'Análisis profundo de ' . $filename . '.',
        ];
    }

    /** @return list<string> */
    private static function batchFilenames(string $userText): array
    {
        preg_match_all('/^## Documento \d+ de \d+: (.+)$/m', $userText, $m);

        return array_map('trim', $m[1]);
    }
}
