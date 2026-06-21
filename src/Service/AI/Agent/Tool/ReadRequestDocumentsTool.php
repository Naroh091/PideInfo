<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Mcp\Service\DocumentContentReader;
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
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Agent tool: reads documents attached to a request and extracts key points
 * from each one using a focused LLM call, rather than dumping raw text into
 * the agent context. Each document is processed independently (subagent-style).
 */
#[AsTool(
    name: 'read_request_documents',
    description: 'Lee los documentos adjuntos a una solicitud del usuario y extrae los puntos clave de cada uno relevantes para la redacción en curso. Cada documento se analiza de forma independiente para identificar fechas, motivos de denegación, argumentos jurídicos y hechos útiles.',
)]
final class ReadRequestDocumentsTool
{
    /** Max raw text characters sent to the extraction LLM per document. */
    private const MAX_TEXT_PER_DOC = 20_000;

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

        /** @var User $user */
        $user = $this->security->getUser();

        if (!Uuid::isValid($requestId)) {
            return 'El ID de solicitud proporcionado no es un UUID válido.';
        }

        $ar = $this->requestRepository->find(Uuid::fromString($requestId));

        if (!$ar instanceof AccessRequest) {
            return 'No se ha encontrado la solicitud con ese ID.';
        }

        if ($ar->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
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

        // Cache the (expensive, ~1 LLM call per document) extraction so repeated
        // reads of the SAME documents within a drafting session — e.g. the
        // planning turn followed by the generation turn — reuse the analysis
        // instead of re-running it. Keyed by user + request + the document set
        // fingerprint (id + createdAt), so any document change busts the cache.
        // The `context` is intentionally NOT part of the key: extraction is
        // about the documents' facts, which don't change with the drafting hint.
        $fingerprint = '';
        foreach ($docs as $doc) {
            $fingerprint .= $doc->getId()->toRfc4122() . ':' . $doc->getCreatedAt()->getTimestamp() . '|';
        }
        $cacheKey = 'agent_doc_extract_' . hash(
            'xxh128',
            $user->getId()->toRfc4122() . '|' . $requestId . '|' . $limit . '|' . $fingerprint,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($docs, $draftingContext, $total, $truncated) {
            $extractions = [];
            $hadError = false;
            foreach ($docs as $doc) {
                $filename = $doc->getOriginalFilename() ?? 'documento';
                $this->progress->step("Leyendo {$filename}…", 'read_request_documents');
                $extraction = $this->extractFromDocument($doc, $draftingContext);
                $hadError = $hadError || $this->isFailedExtraction($extraction);
                $extractions[] = $extraction;
            }

            // Don't lock in a transient failure for an hour: short TTL if any
            // document failed to extract, full TTL otherwise.
            $item->expiresAfter($hadError ? 60 : 3600);

            return $this->formatExtractions($extractions, $total, $truncated);
        });
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
        $rank = static function (object $doc): int {
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
        };

        // usort is not stable across all PHP versions for equal ranks; decorate
        // with the original index to guarantee a stable sort.
        $decorated = [];
        foreach ($docs as $i => $doc) {
            $decorated[] = [$rank($doc), $i, $doc];
        }
        usort($decorated, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $d) => $d[2], $decorated);
    }

    /**
     * @param \App\Entity\Document $doc
     * @return array<string, mixed>
     */
    private function extractFromDocument(object $doc, string $context): array
    {
        $contentDto = $this->contentReader->read($doc, 'text');
        $rawText = $contentDto->content ?? '';

        $filename = $doc->getOriginalFilename() ?? 'Sin nombre';
        $mimeType = $doc->getMimeType();

        if ($rawText === '' || ($contentDto->error ?? null) !== null) {
            return [
                'filename'           => $filename,
                'error'              => $contentDto->error ?? 'no_content',
                'useful_for_drafting' => 'No se pudo extraer contenido textual de este documento.',
                'key_facts'          => [],
                'denial_reasons'     => [],
                'relevant_dates'     => [],
                'document_type'      => 'otro',
            ];
        }

        $excerpt = mb_strlen($rawText) > self::MAX_TEXT_PER_DOC
            ? mb_substr($rawText, 0, self::MAX_TEXT_PER_DOC) . "\n\n[…texto truncado]"
            : $rawText;

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

        $this->progress->step("Analizando {$filename}…", 'read_request_documents');

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
            return [
                'filename'            => $filename,
                'useful_for_drafting' => 'Error al analizar el documento.',
                'key_facts'           => [],
                'denial_reasons'      => [],
                'relevant_dates'      => [],
                'document_type'       => 'otro',
            ];
        }
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

            $blocks[] = implode("\n\n", array_filter([
                "### {$filename} _(tipo: {$docType})_",
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
