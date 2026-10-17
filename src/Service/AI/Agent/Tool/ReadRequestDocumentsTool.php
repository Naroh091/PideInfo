<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Mcp\Service\DocumentContentReader;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Prompt\PromptStore;
use App\Repository\AccessRequestRepository;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Agent tool: reads documents attached to a request and extracts key points
 * from each one using a focused LLM call, rather than dumping raw text into
 * the agent context.
 *
 * Latency design (see PLAN_LATENCIA.md):
 * - Extraction is cached PER DOCUMENT (id + createdAt + type), never per
 *   document-set: regenerating after saving a draft only analyses new docs.
 * - `limit` is NOT part of any cache key: different limits reuse the same
 *   per-document entries.
 * - Procedural paperwork (ranks 2-4) reuses the ingestion AI summary
 *   (Document.extractedText) with zero LLM calls; deep analysis is reserved
 *   for ranks 0-1 (resolution + alegaciones).
 * - Pending deep documents are analysed in ONE batched chatJson call per
 *   ~60k chars of excerpts (split into chunks when larger), executed
 *   concurrently when there is more than one chunk.
 */
#[AsTool(
    name: 'read_request_documents',
    description: 'Lee los documentos adjuntos a una solicitud del usuario y extrae los puntos clave de cada uno relevantes para la redacción en curso. Cada documento se analiza de forma independiente para identificar fechas, motivos de denegación, argumentos jurídicos y hechos útiles.',
)]
final class ReadRequestDocumentsTool
{
    /** Max raw text characters sent to the extraction LLM per document. */
    private const MAX_TEXT_PER_DOC = 20_000;

    /** Approx excerpt budget (chars) per batched LLM call; overflow splits into more batches. */
    private const MAX_CHARS_PER_BATCH = 60_000;

    /** Per-document cache TTLs: full hour for good extractions, 1 minute for failures. */
    private const CACHE_TTL_OK = 3600;
    private const CACHE_TTL_FAIL = 60;

    /** Structured-output schema for per-document extraction (guaranteed valid JSON). */
    private const EXTRACTION_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['document_type', 'key_facts', 'denial_reasons', 'relevant_dates', 'useful_for_drafting'],
        'properties'           => [
            'document_type'       => ['type' => 'string', 'enum' => ['respuesta_administracion', 'silencio', 'recurso', 'escrito_usuario', 'otro']],
            'key_facts'           => ['type' => 'array', 'items' => ['type' => 'string']],
            'denial_reasons'      => ['type' => 'array', 'items' => ['type' => 'string']],
            'relevant_dates'      => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'useful_for_drafting' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        private readonly AccessRequestRepository $requestRepository,
        private readonly DocumentContentReader $contentReader,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly Security $security,
        private readonly AgentProgress $progress,
        private readonly CacheInterface $cache,
        private readonly Tracer $tracer,
    ) {
    }

    /**
     * @param string $requestId UUID de la solicitud cuyos documentos se quieren analizar.
     * @param string $context   Descripción breve de lo que se está redactando (reclamación, alegaciones, etc.) para orientar la extracción.
     * @param int    $limit     Número máximo de documentos a analizar (1-15). Por defecto 10.
     */
    public function __invoke(string $requestId, string $context = '', int $limit = 10): string
    {
        $limit = max(1, min(15, $limit));

        $user = $this->security->getUser();
        $user = $user instanceof User ? $user : null;

        if (!Uuid::isValid($requestId)) {
            return 'El ID de solicitud proporcionado no es un UUID válido.';
        }

        $ar = $this->requestRepository->find(Uuid::fromString($requestId));

        if (!$ar instanceof AccessRequest) {
            return 'No se ha encontrado la solicitud con ese ID.';
        }

        // Anonymous drafting (/redactar): an ownerless request read by an
        // anonymous caller is legitimate — the controller already granted
        // `view` via the session-scoped voter branch, and the orchestrator
        // pins the requestId, so the model cannot reach other requests.
        // Every other owner/caller combination requires an exact match.
        $isAnonymousOnOwnerless = $ar->getUser() === null && $user === null;
        if (!$isAnonymousOnOwnerless
            && $ar->getUser()?->getId()->toRfc4122() !== $user?->getId()->toRfc4122()) {
            throw new AccessDeniedException('No tienes acceso a esta solicitud.');
        }

        $allDocs = $ar->getDocuments()->toArray();
        $total = count($allDocs);

        if ($total === 0) {
            return 'La solicitud no tiene documentos adjuntos.';
        }

        // Prioritise the argument-bearing documents (resolution/denial + the
        // administration's alegaciones) so they are ALWAYS within the analysed
        // slice. Otherwise, with documents stored in arbitrary order, the docs
        // that actually contain the arguments to refute (often added late in the
        // expediente) could fall outside the `limit` and never be read — leaving
        // the agent blind to half the administration's reasoning.
        $allDocs = $this->prioritiseForDrafting($allDocs);

        $docs = array_slice($allDocs, 0, $limit);
        $truncated = $total > $limit;

        $draftingContext = $context !== ''
            ? $context
            : 'Redacción de reclamación o alegaciones relacionadas con la solicitud de acceso a información pública.';

        $userKey = $user?->getId()->toRfc4122() ?? 'anon';

        // Partition the slice: ranks 0-1 go through (cached, batched) deep LLM
        // analysis; ranks 2-4 reuse the ingestion AI summary with no LLM call.
        // A shallow document WITHOUT an ingestion summary falls through to the
        // deep path so it is still read instead of silently dropped.
        /** @var array<int, array<string, mixed>> $ordered index => extraction, in slice order */
        $ordered = [];
        /** @var array<int, array{doc: Document, key: string}> $pending deep docs with a cache miss */
        $pending = [];
        $reused = 0;
        $fromSummary = 0;

        foreach ($docs as $index => $doc) {
            if (self::rankFor($doc) >= 2) {
                $summary = trim((string) $doc->getExtractedText());
                if ($summary !== '') {
                    $ordered[$index] = $this->extractionFromSummary($doc, $summary);
                    $fromSummary++;
                    continue;
                }
            }

            $key = $this->docCacheKey($userKey, $requestId, $doc);
            $item = $this->cache->getItem($key);
            if ($item->isHit() && is_array($item->get())) {
                $ordered[$index] = $item->get();
                $reused++;
                continue;
            }

            $pending[$index] = ['doc' => $doc, 'key' => $key];
        }

        // Read excerpts for cache misses (read errors become failed extractions
        // with a short TTL — no LLM call is spent on them).
        /** @var array<int, array{doc: Document, key: string, excerpt: string}> $jobs */
        $jobs = [];
        foreach ($pending as $index => $p) {
            $filename = $p['doc']->getOriginalFilename() ?? 'documento';
            $this->progress->step("Leyendo {$filename}…", 'read_request_documents');
            [$excerpt, $readError] = $this->readExcerpt($p['doc']);
            if ($excerpt === null) {
                $failed = $this->failedExtraction($filename, $readError);
                $this->cacheWrite($p['key'], $failed, self::CACHE_TTL_FAIL);
                $ordered[$index] = $failed;
                continue;
            }
            $jobs[$index] = ['doc' => $p['doc'], 'key' => $p['key'], 'excerpt' => $excerpt];
        }

        // Pack pending excerpts into ~60k-char batches: one LLM call per batch
        // instead of one per document.
        $batches = [];
        $current = [];
        $currentChars = 0;
        foreach ($jobs as $index => $job) {
            $len = mb_strlen($job['excerpt']);
            if ($current !== [] && $currentChars + $len > self::MAX_CHARS_PER_BATCH) {
                $batches[] = $current;
                $current = [];
                $currentChars = 0;
            }
            $current[$index] = $job;
            $currentChars += $len;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        $batchRequests = [];
        foreach ($batches as $batch) {
            $this->progress->step(
                sprintf('Analizando %d documento(s)…', count($batch)),
                'read_request_documents',
            );
            $batchRequests[] = $this->buildBatchRequest($batch, $draftingContext);
        }

        $analyzed = 0;
        if ($batchRequests !== []) {
            $batchResults = count($batchRequests) === 1
                ? $this->runOneBatch($batches[0], $batchRequests[0], $draftingContext)
                : $this->runBatchesConcurrently($batches, $batchRequests, $draftingContext);

            foreach ($batchResults as $index => $extraction) {
                $ordered[$index] = $extraction;
                $failed = $this->isFailedExtraction($extraction);
                $this->cacheWrite($jobs[$index]['key'], $extraction, $failed ? self::CACHE_TTL_FAIL : self::CACHE_TTL_OK);
                $analyzed++;
            }
        }

        // Self-reported counters on our own `agent.tool.read_request_documents`
        // span: analysed (LLM) vs reused (per-doc cache) vs summary (ingestion,
        // no LLM). A repeat read of the same expediente should show analysed=0.
        $this->tracer->annotate([
            AttributeKeys::AGENT_CACHE_HIT => $analyzed === 0 && ($reused > 0 || $fromSummary > 0),
            AttributeKeys::AGENT_DOCS_TOTAL => count($docs),
            AttributeKeys::AGENT_DOCS_ANALYZED => $analyzed,
            AttributeKeys::AGENT_DOCS_REUSED => $reused,
            AttributeKeys::AGENT_DOCS_SUMMARY => $fromSummary,
            AttributeKeys::AGENT_LLM_BATCHES => count($batchRequests),
        ]);

        ksort($ordered);

        return $this->formatExtractions(array_values($ordered), $total, $truncated);
    }

    private function cacheWrite(string $key, array $extraction, int $ttl): void
    {
        $item = $this->cache->getItem($key);
        $item->set($extraction);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    /**
     * Per-document cache key: user + request + document identity (id,
     * creation timestamp, type). Deliberately WITHOUT `limit` — the agent asks
     * for different limits per turn and the same documents must be reused —
     * and WITHOUT a whole-set fingerprint: adding one document (e.g. saving a
     * draft before regenerating) only analyses the new document.
     */
    private function docCacheKey(string $userKey, string $requestId, Document $doc): string
    {
        return 'agent_doc_extract_v2_' . hash('xxh128', implode('|', [
            $userKey,
            $requestId,
            $doc->getId()->toRfc4122(),
            (string) $doc->getCreatedAt()->getTimestamp(),
            $doc->getType()->value,
        ]));
    }

    /**
     * @param array<string, mixed> $extraction
     */
    private function isFailedExtraction(array $extraction): bool
    {
        return isset($extraction['error'])
            || ($extraction['useful_for_drafting'] ?? '') === 'Error al analizar el documento.';
    }

    /**
     * Reorder documents so the ones that carry the administration's arguments
     * (the resolution being challenged and its alegaciones) come first, the
     * request itself next (to know what was asked), and purely procedural
     * paperwork (receipts, processing-start notices) last. Stable within each
     * rank so original order is preserved for ties.
     *
     * @param array<int, \App\Entity\Document> $docs
     * @return array<int, \App\Entity\Document>
     */
    private function prioritiseForDrafting(array $docs): array
    {
        // usort is not stable across all PHP versions for equal ranks; decorate
        // with the original index to guarantee a stable sort.
        $decorated = [];
        foreach ($docs as $i => $doc) {
            $decorated[] = [self::rankFor($doc), $i, $doc];
        }
        usort($decorated, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $d) => $d[2], $decorated);
    }

    /**
     * Drafting priority rank: 0-1 carry the administration's arguments (deep LLM
     * analysis); 2-4 are procedural paperwork or the request itself (ingestion
     * summary is enough, no LLM call).
     */
    private static function rankFor(Document $doc): int
    {
        return match ($doc->getType()) {
            DocumentType::Response,
            DocumentType::ComplaintResolution      => 0, // the act being challenged
            DocumentType::Alegaciones,
            DocumentType::AlegationResponse,
            DocumentType::Audiencia                => 1, // arguments to refute
            DocumentType::ThirdPartyRights,
            DocumentType::Subsanacion,
            DocumentType::SubsanacionResponse,
            DocumentType::Extension,
            DocumentType::Redirection              => 2,
            DocumentType::Request                  => 3, // what was asked
            default                                => 4, // receipts, notifications, other
        };
    }

    /**
     * Reads a document's text and truncates it to the per-document budget.
     *
     * @return array{0: ?string, 1: ?string} [excerpt or null, error code or null]
     */
    private function readExcerpt(Document $doc): array
    {
        $contentDto = $this->contentReader->read($doc, 'text');
        $rawText = $contentDto->content ?? '';

        if ($rawText === '' || ($contentDto->error ?? null) !== null) {
            return [null, $contentDto->error ?? 'no_content'];
        }

        $excerpt = mb_strlen($rawText) > self::MAX_TEXT_PER_DOC
            ? mb_substr($rawText, 0, self::MAX_TEXT_PER_DOC) . "\n\n[…texto truncado]"
            : $rawText;

        return [$excerpt, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedExtraction(string $filename, ?string $error = null): array
    {
        $extraction = [
            'filename'            => $filename,
            'useful_for_drafting' => $error === null
                ? 'Error al analizar el documento.'
                : 'No se pudo extraer contenido textual de este documento.',
            'key_facts'          => [],
            'denial_reasons'     => [],
            'relevant_dates'     => [],
            'document_type'      => 'otro',
        ];
        if ($error !== null) {
            $extraction['error'] = $error;
        }

        return $extraction;
    }

    /**
     * Procedural-paperwork path: no LLM call — the ingestion AI summary
     * (Document.extractedText) is used directly as the drafting relevance note.
     *
     * @return array<string, mixed>
     */
    private function extractionFromSummary(Document $doc, string $summary): array
    {
        return [
            'filename'            => $doc->getOriginalFilename() ?? 'documento',
            'document_type'       => self::summaryDocType($doc->getType()),
            'key_facts'           => [],
            'denial_reasons'      => [],
            'relevant_dates'      => [],
            'useful_for_drafting' => $summary,
            'source'              => 'ingest_summary',
        ];
    }

    /**
     * Best-effort mapping of the catalogue type onto the extraction schema's
     * document_type enum (used only for the no-LLM summary path).
     */
    private static function summaryDocType(DocumentType $type): string
    {
        return match (true) {
            $type === DocumentType::Response,
            $type === DocumentType::ComplaintResolution => 'respuesta_administracion',
            $type === DocumentType::Complaint,
            $type === DocumentType::CourtAppeal         => 'recurso',
            $type === DocumentType::Request,
            $type === DocumentType::AlegationResponse,
            $type === DocumentType::SubsanacionResponse => 'escrito_usuario',
            default                                     => 'otro',
        };
    }

    /**
     * Single batched LLM call for a group of deep documents: their excerpts
     * concatenated, one `extractions` list in the response (same order, the
     * filename echoed per item for robust mapping).
     *
     * @param array<int, array{doc: Document, key: string, excerpt: string}> $batch
     */
    private function buildBatchRequest(array $batch, string $context): ChatRequest
    {
        $prompt = $this->promptStore->compile('pideinfo-document-extract-for-drafting', [
            'context' => $context,
        ]);

        $blocks = [];
        $n = count($batch);
        $k = 0;
        foreach ($batch as $job) {
            $k++;
            $filename = $job['doc']->getOriginalFilename() ?? 'Sin nombre';
            $blocks[] = sprintf("## Documento %d de %d: %s\n\n%s", $k, $n, $filename, $job['excerpt']);
        }

        $userText = 'Analiza cada uno de los siguientes documentos de forma INDEPENDIENTE. '
            . 'Responde con un JSON con la clave "extractions": una lista con UN objeto por documento, '
            . 'EN EL MISMO ORDEN en que aparecen, copiando en cada objeto su "filename" tal cual.' . "\n\n"
            . implode("\n\n---\n\n", $blocks);

        return new ChatRequest(
            systemPrompt: $prompt,
            userText: $userText,
            jsonSchema: self::batchSchema(),
            schemaName: 'document_extraction_batch',
            maxOutputTokens: min(1024 * $n, 8192),
            maxRetries: 1,
            requiredJsonKeys: ['extractions'],
            label: 'agent.document.extract',
        );
    }

    /**
     * Batch response schema: the per-document extraction schema as a list,
     * each item echoing its filename so responses map back to documents.
     *
     * @return array<string, mixed>
     */
    private static function batchSchema(): array
    {
        $item = self::EXTRACTION_SCHEMA;
        $item['required'][] = 'filename';
        $item['properties'] = ['filename' => ['type' => 'string']] + $item['properties'];

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['extractions'],
            'properties'           => [
                'extractions' => ['type' => 'array', 'items' => $item],
            ],
        ];
    }

    /**
     * Runs a single batch through the LLM; falls back to one call per document
     * when the batch response is unusable, so one malformed batch never blinds
     * the agent to the whole expediente.
     *
     * @param array<int, array{doc: Document, key: string, excerpt: string}> $batch
     * @return array<int, array<string, mixed>> index => extraction
     */
    private function runOneBatch(array $batch, ChatRequest $request, string $context): array
    {
        try {
            $normalized = $this->normalizeBatchResult($batch, $this->llmClient->chatJson($request));
            if ($normalized !== null) {
                return $normalized;
            }
        } catch (\Throwable) {
            // fall through to the per-document fallback below
        }

        return $this->extractBatchSequentially($batch, $context);
    }

    /**
     * Runs several batches CONCURRENTLY (multiplexed streaming HTTP). A failed
     * round — or any unusable batch response — is retried SEQUENTIALLY batch
     * by batch (each with its own per-document fallback), so concurrency is
     * strictly a latency optimisation that never loses a document.
     *
     * @param list<array<int, array{doc: Document, key: string, excerpt: string}>> $batches
     * @param list<ChatRequest> $requests
     * @return array<int, array<string, mixed>> index => extraction
     */
    private function runBatchesConcurrently(array $batches, array $requests, string $context): array
    {
        $out = [];
        $failedBatches = [];

        try {
            $decodedList = $this->llmClient->chatJsonConcurrent($requests);
            foreach ($batches as $b => $batch) {
                $normalized = $this->normalizeBatchResult($batch, $decodedList[$b] ?? null);
                if ($normalized === null) {
                    $failedBatches[] = $b;
                    continue;
                }
                foreach ($normalized as $index => $extraction) {
                    $out[$index] = $extraction;
                }
            }
        } catch (\Throwable) {
            $failedBatches = array_keys($batches);
        }

        foreach ($failedBatches as $b) {
            foreach ($this->runOneBatch($batches[$b], $requests[$b], $context) as $index => $extraction) {
                $out[$index] = $extraction;
            }
        }

        return $out;
    }

    /**
     * Per-document fallback: today's one-call-per-document extraction, used when
     * a batch response is unusable. Kept on the same prompt and schema so the
     * output shape is identical to the batch path.
     *
     * @param array<int, array{doc: Document, key: string, excerpt: string}> $batch
     * @return array<int, array<string, mixed>> index => extraction
     */
    private function extractBatchSequentially(array $batch, string $context): array
    {
        $out = [];
        foreach ($batch as $index => $job) {
            $filename = $job['doc']->getOriginalFilename() ?? 'Sin nombre';
            $this->progress->step("Analizando {$filename}…", 'read_request_documents');
            $out[$index] = $this->extractSingleFromExcerpt($job['doc'], $job['excerpt'], $context);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSingleFromExcerpt(Document $doc, string $excerpt, string $context): array
    {
        $filename = $doc->getOriginalFilename() ?? 'Sin nombre';
        $mimeType = $doc->getMimeType();

        // System prompt = instructions only (no document content).
        // User turn = document content — models handle "text to analyse" better
        // when it arrives in the user turn rather than buried in the system prompt.
        $prompt = $this->promptStore->compile('pideinfo-document-extract-for-drafting', [
            'context' => $context,
        ]);

        $userText = sprintf(
            "**Documento:** %s\n**Tipo:** %s\n\n**Contenido:**\n\n%s",
            $filename,
            $mimeType,
            $excerpt,
        );

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                userText: $userText,
                jsonSchema: self::EXTRACTION_SCHEMA,
                schemaName: 'document_extraction',
                maxOutputTokens: 1024,
                maxRetries: 1,
                requiredJsonKeys: ['useful_for_drafting'],
                label: 'agent.document.extract',
            ));

            return array_merge(['filename' => $filename], $result);
        } catch (\Throwable) {
            return $this->failedExtraction($filename);
        }
    }

    /**
     * Maps a batch response back to document indexes. Assigns by echoed
     * filename when every filename matches a pending document (robust against
     * reordering), by position otherwise. Returns null when the response is
     * unusable (wrong item count) so the caller retries per document.
     *
     * @param array<int, array{doc: Document, key: string, excerpt: string}> $batch
     * @return array<int, array<string, mixed>>|null index => extraction
     */
    private function normalizeBatchResult(array $batch, mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        $items = $decoded['extractions'] ?? null;
        if (!is_array($items) || count($items) !== count($batch)) {
            return null;
        }
        $items = array_values($items);
        $keys = array_keys($batch);

        $byFilename = [];
        foreach ($batch as $index => $job) {
            $byFilename[$job['doc']->getOriginalFilename() ?? 'Sin nombre'] = $index;
        }
        // Filename matching only when filenames are unique and every echoed
        // filename matches; otherwise positional assignment.
        $matchByName = count($byFilename) === count($batch);
        if ($matchByName) {
            foreach ($items as $item) {
                if (!is_array($item) || !isset($byFilename[$item['filename'] ?? null])) {
                    $matchByName = false;
                    break;
                }
            }
        }

        $out = [];
        foreach ($items as $pos => $item) {
            if (!is_array($item)) {
                return null;
            }
            $index = $matchByName ? $byFilename[$item['filename']] : $keys[$pos];
            $out[$index] = [
                'filename'            => $batch[$index]['doc']->getOriginalFilename() ?? 'Sin nombre',
                'document_type'       => $item['document_type'] ?? 'otro',
                'key_facts'           => $item['key_facts'] ?? [],
                'denial_reasons'      => $item['denial_reasons'] ?? [],
                'relevant_dates'      => $item['relevant_dates'] ?? [],
                'useful_for_drafting' => $item['useful_for_drafting'] ?? 'Error al analizar el documento.',
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $extractions
     */
    private function formatExtractions(array $extractions, int $total, bool $truncated): string
    {
        $blocks = [];
        foreach ($extractions as $e) {
            $filename = $e['filename'] ?? '—';
            $docType = $e['document_type'] ?? 'otro';

            if (isset($e['error'])) {
                $blocks[] = "### {$filename}\n_No se pudo extraer contenido ({$e['error']})._";
                continue;
            }

            $facts = !empty($e['key_facts'])
                ? "**Hechos clave:**\n- " . implode("\n- ", (array) $e['key_facts'])
                : '';

            $denial = !empty($e['denial_reasons'])
                ? "**Motivos de denegación:**\n- " . implode("\n- ", (array) $e['denial_reasons'])
                : '';

            $dates = !empty($e['relevant_dates'])
                ? "**Fechas relevantes:**\n" . implode("\n", array_map(
                    fn ($k, $v) => "- {$k}: {$v}",
                    array_keys((array) $e['relevant_dates']),
                    array_values((array) $e['relevant_dates']),
                ))
                : '';

            $useful = $e['useful_for_drafting'] ?? '';

            $parts = array_filter([$facts, $denial, $dates]);

            // Summary-sourced blocks are labelled as such so the model knows
            // they carry less detail than a deep-analysed document.
            $origin = ($e['source'] ?? null) === 'ingest_summary' ? '; resumen de ingesta' : '';
            $blocks[] = implode("\n\n", array_filter([
                "### {$filename} _(tipo: {$docType}{$origin})_",
                $useful !== '' ? "**Relevancia para la redacción:** {$useful}" : '',
                ...$parts,
            ]));
        }

        $header = sprintf(
            "**%d documento(s) analizados**%s:",
            count($extractions),
            $truncated ? " (de {$total} totales; muestra los primeros " . count($extractions) . ')' : '',
        );

        return $header . "\n\n" . implode("\n\n---\n\n", $blocks);
    }
}
