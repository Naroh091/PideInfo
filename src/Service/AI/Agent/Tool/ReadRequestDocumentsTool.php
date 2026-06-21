<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
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

    public function __construct(
        private readonly AccessRequestRepository $requestRepository,
        private readonly DocumentContentReader $contentReader,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly Security $security,
        private readonly AgentProgress $progress,
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

        $docs = array_slice($allDocs, 0, $limit);
        $truncated = $total > $limit;

        $draftingContext = $context !== ''
            ? $context
            : 'Redacción de reclamación o alegaciones relacionadas con la solicitud de acceso a información pública.';

        $extractions = [];
        foreach ($docs as $doc) {
            $filename = $doc->getOriginalFilename() ?? 'documento';
            $this->progress->step("Leyendo {$filename}…", 'read_request_documents');
            $extractions[] = $this->extractFromDocument($doc, $draftingContext);
        }

        return $this->formatExtractions($extractions, $total, $truncated);
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
