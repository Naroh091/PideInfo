<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Batch;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;

/**
 * Turns the unified batch template into the version of ONE recipient.
 *
 * One LLM call per recipient, with that recipient's own request prompt
 * ({@see RequestPromptComposer}: organism, channel shape, literal applicable
 * law, output contract) and the template as the instruction: resolve the
 * markers, adapt the legal references to the applicable law and the shape to the
 * channel, without touching the substance. If the model fails or answers with a
 * question, the markers are filled deterministically ({@see BatchPlaceholders})
 * so the user never ends up with nothing — the result says which path was used.
 *
 * The version keeps its OWN conversation thread: a turn is appended to it saying
 * where the text came from, so the per-organism assistant knows it is adjusting
 * an adaptation of the common template (and the user sees it in that thread).
 */
class BatchVersionGenerator
{
    public const METADATA_KEY = 'batch_version';
    private const CHAT_HISTORY_KEY = 'draft_chat_history';
    private const CHAT_HISTORY_CAP = 60;

    public function __construct(
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly RequestDraftGenerator $draftGenerator,
        private readonly LlmClient $llmClient,
        private readonly ChatHistoryStore $chatHistoryStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Applies the adapted template to `$ar` (not flushed) and records the
     * provenance in its metadata and conversation thread.
     *
     * @return array{draft: array<string, mixed>, fallback: bool}
     */
    public function generate(AccessRequest $ar, DraftBatchTemplate $template, User $user): array
    {
        $fallback = false;
        try {
            $draft = $this->adaptWithModel($ar, $template);
        } catch (\Throwable $e) {
            $this->logger->warning('BatchVersionGenerator: model adaptation failed, filling markers deterministically', [
                'access_request_id' => $ar->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            $draft = $this->fillDeterministically($ar, $template);
            $fallback = true;
        }

        $normalized = $this->draftGenerator->applyDraft($ar, $draft);
        // REG asunto hard-caps at 80 (dispatch rejects longer ones): a version
        // must come out sendable without a detour through the error.
        if ($ar->getRegDestination() !== null && mb_strlen($ar->getTitle()) > 80) {
            $ar->setTitle(rtrim(mb_substr($ar->getTitle(), 0, 80)));
            $normalized['title'] = $ar->getTitle();
        }
        $ar->setMetadataValue('cited_sources', $template->sources);
        $ar->setMetadataValue(self::METADATA_KEY, [
            'template_fingerprint' => $template->fingerprint(),
            'content_fingerprint' => self::contentFingerprint($ar),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'fallback' => $fallback,
        ]);

        $this->recordInThread($ar, $user, $fallback);

        return ['draft' => $normalized, 'fallback' => $fallback];
    }

    /**
     * Identity of a version's text. Compared against the value stored at
     * generation time to tell whether the version was adjusted afterwards.
     */
    public static function contentFingerprint(AccessRequest $ar): string
    {
        return sha1($ar->getTitle() . "\x1f" . (string) $ar->getDescription());
    }

    /**
     * @return array<string, mixed>
     */
    private function adaptWithModel(AccessRequest $ar, DraftBatchTemplate $template): array
    {
        $composed = $this->requestPromptComposer->compose($ar, []);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: $composed,
            userText: $this->instruction($ar, $template),
            requiredJsonKeys: ['action'],
            label: 'BatchVersionAdaptation',
            traceName: 'BatchVersionAdaptation',
            promptRef: $composed,
        ));

        $draft = $data['draft'] ?? null;
        if (!in_array($data['action'] ?? null, ['generate', 'rewrite'], true) || !is_array($draft)) {
            throw new \RuntimeException('The model did not return a draft.');
        }

        if ($ar->getRegDestination() === null && trim((string) ($draft['expone'] ?? '') . (string) ($draft['solicita'] ?? '')) !== '') {
            $draft['body_text'] = self::portalBody((string) ($draft['expone'] ?? ''), (string) ($draft['solicita'] ?? ''));
        }

        // A marker that survived the adaptation would reach the organism verbatim.
        $text = implode("\n", array_map('strval', array_filter($draft, 'is_scalar')));
        if (BatchPlaceholders::hasMarkers($text)) {
            throw new \RuntimeException('The adapted draft still contains template markers.');
        }

        return $draft;
    }

    /**
     * @return array<string, string>
     */
    private function fillDeterministically(AccessRequest $ar, DraftBatchTemplate $template): array
    {
        $title = BatchPlaceholders::fill($template->title, $ar);
        $expone = BatchPlaceholders::fill($template->expone, $ar);
        $solicita = BatchPlaceholders::fill($template->solicita, $ar);

        if ($ar->getRegDestination() !== null) {
            return ['title' => mb_substr($title, 0, 80), 'expone' => $expone, 'solicita' => $solicita];
        }

        return ['title' => $title, 'body_text' => self::portalBody($expone, $solicita)];
    }

    /**
     * Single-body channels (portal/email) keep the template's EXPONE/SOLICITA
     * structure — the same layout REG drafts mirror into `description` — and
     * its paragraphs and lists untouched.
     */
    public static function portalBody(string $expone, string $solicita): string
    {
        $parts = [];
        if (trim($expone) !== '') {
            $parts[] = "EXPONE:\n" . trim($expone);
        }
        if (trim($solicita) !== '') {
            $parts[] = "SOLICITA:\n" . trim($solicita);
        }

        return implode("\n\n", $parts);
    }

    private function instruction(AccessRequest $ar, DraftBatchTemplate $template): string
    {
        $values = '';
        foreach (BatchPlaceholders::valuesFor($ar) as $name => $value) {
            $values .= sprintf("- %s → %s\n", BatchPlaceholders::marker($name), $value !== '' ? $value : '(sin valor: redacta sin esa referencia)');
        }

        // Same shape for every channel: the portal's single body is assembled
        // here from expone/solicita (portalBody()), so the model never has to
        // merge them — when it did, it flattened paragraphs and lists into one.
        $titleCap = $ar->getRegDestination() !== null ? 80 : 255;

        return <<<TXT
La persona usuaria envía la MISMA solicitud a varios organismos y ha redactado una plantilla común. Genera AHORA (acción `generate`) la versión para el organismo de este contexto. Ignora el borrador actual del canal, si lo hay: se sustituye por esta versión.

Reglas:
1. Sustituye cada marcador por su valor:
{$values}2. Adapta las referencias legales a la ley aplicable de este contexto. Cita solo artículos que aparezcan en el bloque de marco legal de este prompt; si no hay ninguno adecuado, invoca la ley sin número de artículo.
3. NO cambies el fondo: misma información pedida, mismos argumentos y mismas citas de resoluciones o sentencias que la plantilla. No añadas peticiones nuevas.
4. Ajusta solo lo imprescindible para que el texto suene natural dirigido a este organismo (concordancias, artículos «el/la», nombre del organismo). No añadas introducciones, párrafos ni fórmulas que no estén en la plantilla.
5. El resultado no puede contener ningún marcador [[...]].
6. Conserva EXACTAMENTE la estructura de la plantilla: los mismos párrafos (separados por una línea en blanco), los saltos de línea y las listas numeradas, cada elemento en su línea. Texto plano, sin HTML.
7. Devuelve SIEMPRE el draft con `title` (máx. {$titleCap} caracteres), `expone` y `solicita` por separado, aunque este canal use un cuerpo único: el cuerpo lo compone la aplicación.
8. En `conversational_reply` resume en una frase qué has adaptado. En `sources` repite las de la plantilla que sigan citadas.

PLANTILLA COMÚN
ASUNTO: {$template->title}
EXPONE:
{$template->expone}
SOLICITA:
{$template->solicita}
TXT;
    }

    private function recordInThread(AccessRequest $ar, User $user, bool $fallback): void
    {
        $content = $fallback
            ? '<p>✦ He creado esta versión a partir de la <strong>plantilla común</strong> sustituyendo las partes variables (organismo, unidad y ley). No he podido revisar la redacción con el asistente: léela antes de enviarla. Lo que cambies aquí solo afecta a este organismo.</p>'
            : '<p>✦ He adaptado la <strong>plantilla común</strong> a este organismo: nombre, unidad de destino y ley aplicable. Lo que me pidas en esta conversación, o lo que edites en la hoja, solo cambia la solicitud de este organismo.</p>';

        $this->chatHistoryStore->append(
            threadId: ChatHistoryStore::threadIdForRequest($ar),
            user: $user,
            newTurns: [[
                'role' => 'assistant',
                'kind' => 'text',
                'action' => 'generate',
                'content' => $content,
                'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]],
            maxTurns: self::CHAT_HISTORY_CAP,
            ar: $ar,
            metadataKey: self::CHAT_HISTORY_KEY,
        );
    }
}
