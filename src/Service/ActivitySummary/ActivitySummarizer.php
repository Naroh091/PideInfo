<?php

namespace App\Service\ActivitySummary;

use App\Entity\User;
use App\Entity\UserNotification;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Repository\UserNotificationRepository;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;

/**
 * Builds a 1–2 paragraph HTML summary (allowed tags: <b>, <i>) of the user's
 * activity over the last 24 h based on their notifications.
 *
 * Same shape as `App\Service\Complaint\SuccessAnalyzer`: chatJson + post-hoc
 * sanitize + budget enforcement. Returns `null` when there is nothing to
 * summarize or the model fails (the caller is responsible for not persisting
 * a stale cache in either case).
 */
final class ActivitySummarizer
{
    /** Total character budget enforced both via prompt and post-hoc trimming. */
    public const MAX_CHARS = 1200;

    public function __construct(
        private readonly UserNotificationRepository $notificationRepository,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return UserNotification[] in chronological order
     */
    public function notificationsSince(User $user, \DateTimeImmutable $since): array
    {
        return $this->notificationRepository->findSinceByUser($user, $since);
    }

    /**
     * sha1 of the ordered notification UUIDs + the generation parameters the
     * cached summary was built with. If this changes vs. what we have in
     * cache, the summary is stale and gets regenerated.
     *
     * The MAX_CHARS suffix makes any change to the budget auto-invalidate
     * existing caches — without it, bumping the cap had no effect on users
     * whose 24 h notification set hadn't changed.
     *
     * @param UserNotification[] $notifications
     */
    public function fingerprint(array $notifications): string
    {
        $ids = array_map(static fn (UserNotification $n) => (string) $n->getId(), $notifications);
        sort($ids);
        return sha1(implode('|', $ids) . '||v=' . self::MAX_CHARS);
    }

    /**
     * Generate the HTML summary for a user. Returns `null` when there are no
     * notifications in the window or the LLM call fails. On success returns
     * trusted HTML restricted to <b>/<i> plus inline `<a>` badges that link to
     * the mentioned solicitudes, capped at MAX_CHARS (text + badges).
     */
    public function summarize(User $user, \DateTimeImmutable $since): ?string
    {
        $notifications = $this->notificationsSince($user, $since);
        if ($notifications === []) {
            return null;
        }

        // Whitelist of solicitud UUIDs the model is allowed to reference. Anything
        // outside this set in `references` gets silently dropped.
        $allowedUuids = [];
        foreach ($notifications as $n) {
            $req = $n->getAccessRequest();
            if ($req !== null) {
                $allowedUuids[(string) $req->getId()] = true;
            }
        }

        $prompt = $this->buildPrompt($user, $notifications);

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                temperature: 1.0,
                jsonSchema: $this->buildResponseSchema(),
                schemaName: 'activity_summary',
                maxOutputTokens: 1024,
                requiredJsonKeys: ['summary', 'references'],
                label: 'activity.summary_24h',
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('ActivitySummarizer failed', [
                'user' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $html = $this->sanitize((string) ($result['summary'] ?? ''));
        if ($html === '') {
            return null;
        }

        // Budget is enforced on the SANITIZED text (what the user reads), not on
        // the final HTML — the reference `<a>` badges are presentational and
        // their markup (title + aria-label + href) would otherwise eat a huge
        // chunk of the cap and truncate the actual summary mid-sentence.
        $html = $this->trimToBudget($html, self::MAX_CHARS);

        $references = is_array($result['references'] ?? null) ? $result['references'] : [];
        return $this->injectReferenceBadges($html, $references, $allowedUuids);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Resumen 1-2 párrafos en HTML restringido a <b> e <i>. Máximo 1200 caracteres totales.',
                ],
                'references' => [
                    'type' => 'array',
                    'description' => 'Una entrada por cada solicitud mencionada cuyo UUID conocemos. El sistema añadirá una badge "↗" inline tras la mención que abrirá la solicitud.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => [
                                'type' => 'string',
                                'description' => 'Texto exacto envuelto en <b>...</b> en el summary que identifica a esta solicitud.',
                            ],
                            'uuid' => [
                                'type' => 'string',
                                'description' => 'UUID de la solicitud, tal cual aparece entre llaves en los eventos del input.',
                            ],
                        ],
                        'required' => ['label', 'uuid'],
                    ],
                ],
            ],
            'required' => ['summary', 'references'],
        ];
    }

    /**
     * @param UserNotification[] $notifications
     */
    private function buildPrompt(User $user, array $notifications): CompiledPrompt
    {
        $context = sprintf(
            'Usuario: %s. Total de eventos en la ventana: %d.',
            $user->getFullName(),
            count($notifications),
        );

        $lines = [];
        foreach ($notifications as $n) {
            $req = $n->getAccessRequest();
            $reqLabel = $req !== null
                ? sprintf('{%s} "%s" (%s)', $req->getId(), $req->getTitle(), $req->getPublicBody()?->getName() ?? 'organismo desconocido')
                : 'sin solicitud asociada';

            $lines[] = sprintf(
                '- [%s] %s · solicitud %s · "%s"',
                $n->getCreatedAt()->format('d/m H:i'),
                $n->getTypeLabel(),
                $reqLabel,
                trim((string) $n->getMessage()),
            );
        }

        return $this->promptStore->compile('pideinfo-activity-summary-24h', [
            'user_context' => $context,
            'notifications_block' => implode("\n", $lines),
        ]);
    }

    private function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // Allow only <b> and <i>. Anything else (incl. <p>, <br>, lists, markdown
        // converted to tags) gets stripped — the prompt asks for compliance and
        // this is the safety net. The reference `<a>` badges are added by us
        // *after* sanitization, so they don't need to be in the allow-list.
        return trim(strip_tags($html, ['b', 'i']));
    }

    /**
     * Inject "open in new tab" badges next to each `<b>{label}</b>` mention that
     * the model declared as a reference. Unmatched references go to a footer
     * span so the user can still reach the solicitud.
     *
     * @param array<int, mixed>      $references  Raw `references` payload from the LLM.
     * @param array<string, true>    $allowedUuids Set of UUIDs (from input) the model is allowed to reference.
     */
    private function injectReferenceBadges(string $html, array $references, array $allowedUuids): string
    {
        if ($references === []) {
            return $html;
        }

        $matchedUuids = [];
        $unmatched = [];

        foreach ($references as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $uuid = isset($ref['uuid']) ? trim((string) $ref['uuid']) : '';
            $label = isset($ref['label']) ? trim(strip_tags((string) $ref['label'])) : '';

            if ($uuid === '' || $label === '') {
                continue;
            }
            if (!isset($allowedUuids[$uuid])) {
                continue; // hallucinated UUID, drop silently
            }
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                continue;
            }
            if (isset($matchedUuids[$uuid])) {
                continue; // same solicitud already linked
            }

            $needle = '<b>' . $label . '</b>';
            $badge = $this->buildBadge($uuid, $label);

            $pos = strpos($html, $needle);
            if ($pos !== false) {
                // Insert the badge right after the closing </b> of the matched mention.
                $insertAt = $pos + strlen($needle);
                $html = substr($html, 0, $insertAt) . $badge . substr($html, $insertAt);
                $matchedUuids[$uuid] = true;
            } else {
                $unmatched[] = ['uuid' => $uuid, 'label' => $label];
            }
        }

        if ($unmatched !== []) {
            $html .= ' <span class="ref-fallback">';
            foreach ($unmatched as $u) {
                $html .= ' ' . $this->buildBadge($u['uuid'], $u['label']);
            }
            $html .= '</span>';
        }

        return $html;
    }

    private function buildBadge(string $uuid, string $label): string
    {
        // No router dependency: the show route is /solicitudes/{id} and has been
        // stable; if it changes, this one place + the catalog need updating.
        // `title` doubles as the accessible name on hover so we skip a separate
        // aria-label (smaller markup, same a11y outcome).
        $href = '/solicitudes/' . $uuid;
        $title = sprintf('Abrir: %s', $label);

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="ref-badge" title="%s">↗</a>',
            htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    private function trimToBudget(string $html, int $budget): string
    {
        if (mb_strlen($html) <= $budget) {
            return $html;
        }
        return rtrim(mb_substr($html, 0, max(0, $budget - 1))) . '…';
    }
}
