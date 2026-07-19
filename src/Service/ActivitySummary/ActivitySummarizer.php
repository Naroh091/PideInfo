<?php

namespace App\Service\ActivitySummary;

use App\Entity\User;
use App\Entity\UserNotification;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Repository\UserNotificationRepository;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\Deadline\UpcomingDeadlineCollector;
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

    /**
     * Bumped whenever the output contract changes (e.g. the structured items
     * added for the redesigned panel): folded into the fingerprint so every
     * cached summary regenerates under the new contract.
     */
    private const FORMAT_VERSION = 5;

    /** Hard cap on structured items — the dashboard card is a shortlist, not a feed. */
    private const MAX_ITEMS = 6;

    /** Hard cap on solicitudes per item — the «Ver» dialog is a peek, not a listing. */
    private const MAX_ITEM_UUIDS = 12;

    private const ITEM_KINDS = ['estimacion', 'alegaciones', 'silencio', 'inadmision', 'denegacion', 'caducidad', 'otro'];
    private const ITEM_SEVERITIES = ['exito', 'aviso', 'fallo', 'curso', 'neutro'];

    /**
     * Look-ahead window for the "lo que viene" prompt context. Matches the
     * DeadlineAlerts default so the closing sentence and the alerts card talk
     * about the same deadlines.
     */
    public const UPCOMING_DAYS = 7;

    public function __construct(
        private readonly UserNotificationRepository $notificationRepository,
        private readonly UpcomingDeadlineCollector $deadlineCollector,
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
     * sha1 of the ordered notification UUIDs + the upcoming-deadlines state +
     * the generation parameters the cached summary was built with. If this
     * changes vs. what we have in cache, the summary is stale and gets
     * regenerated.
     *
     * The MAX_CHARS suffix makes any change to the budget auto-invalidate
     * existing caches — without it, bumping the cap had no effect on users
     * whose 24 h notification set hadn't changed.
     *
     * The upcoming part serializes id + date + daysUntil: daysUntil shrinks
     * every day, so a user with deadlines in the window regenerates at most
     * once per day even without new notifications — deliberate, the closing
     * "quedan N días" must not go stale.
     *
     * @param UserNotification[] $notifications
     */
    public function fingerprint(User $user, array $notifications): string
    {
        $ids = array_map(static fn (UserNotification $n) => (string) $n->getId(), $notifications);
        sort($ids);

        $upcoming = array_map(
            static fn (array $a) => sprintf('%s:%s:%d', $a['id'], $a['deadlineAt']->format('Y-m-d'), $a['daysUntil']),
            $this->deadlineCollector->collect($user, self::UPCOMING_DAYS),
        );
        sort($upcoming);

        return sha1(implode('|', $ids) . '||up=' . implode('|', $upcoming) . '||v=' . self::MAX_CHARS . '||fmt=' . self::FORMAT_VERSION);
    }

    /**
     * Generate the summary for a user. Returns `null` when there are no
     * notifications in the window or the LLM call fails. On success returns
     * the narrative HTML (restricted to <b>/<i> plus inline `<a>` badges that
     * link to the mentioned solicitudes, capped at MAX_CHARS) together with
     * the sanitized structured items for the dashboard.
     */
    public function summarize(User $user, \DateTimeImmutable $since): ?SummaryResult
    {
        $notifications = $this->notificationsSince($user, $since);
        if ($notifications === []) {
            return null;
        }

        $upcoming = $this->deadlineCollector->collect($user, self::UPCOMING_DAYS);

        // Whitelist of solicitud UUIDs the model is allowed to reference. Anything
        // outside this set in `references` gets silently dropped. Upcoming
        // deadlines count too: the closing "lo que viene" sentence may mention
        // a solicitud that had no notification in the window.
        $allowedUuids = [];
        foreach ($notifications as $n) {
            $req = $n->getAccessRequest();
            if ($req !== null) {
                $allowedUuids[(string) $req->getId()] = true;
            }
        }
        foreach ($upcoming as $alert) {
            $allowedUuids[$alert['id']] = true;
        }

        $prompt = $this->buildPrompt($user, $notifications, $upcoming);

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                temperature: 1.0,
                jsonSchema: $this->buildResponseSchema(),
                schemaName: 'activity_summary',
                maxOutputTokens: 2048,
                requiredJsonKeys: ['summary', 'items', 'references'],
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
        $html = $this->injectReferenceBadges($html, $references, $allowedUuids);

        $items = $this->sanitizeItems(is_array($result['items'] ?? null) ? $result['items'] : [], $allowedUuids);

        return new SummaryResult($html, $items);
    }

    /**
     * Validate and sanitize the structured items: plain text only, enums
     * enforced, UUIDs outside the whitelist dropped (the item survives without
     * its link), hard cap at MAX_ITEMS. An item without title is
     * discarded — there is nothing to render.
     *
     * @param array<int, mixed>   $items
     * @param array<string, true> $allowedUuids
     *
     * @return list<array<string, string|list<string>>>
     */
    private function sanitizeItems(array $items, array $allowedUuids): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $text = static fn (string $key, int $max): string => mb_substr(trim(strip_tags((string) ($item[$key] ?? ''))), 0, $max);

            $title = $text('title', 120);
            if ($title === '') {
                continue;
            }

            $kind = (string) ($item['kind'] ?? '');
            $severity = (string) ($item['severity'] ?? '');

            $entry = [
                'kind' => in_array($kind, self::ITEM_KINDS, true) ? $kind : 'otro',
                'severity' => in_array($severity, self::ITEM_SEVERITIES, true) ? $severity : 'neutro',
                'title' => $title,
                'detail' => $text('detail', 160),
                'action' => $text('action', 40),
            ];

            // `uuids` (lista) es el contrato actual; se acepta también el
            // `uuid` singular por si el modelo recae en el formato viejo.
            $rawUuids = is_array($item['uuids'] ?? null) ? $item['uuids'] : [];
            if (isset($item['uuid'])) {
                $rawUuids[] = $item['uuid'];
            }
            $uuids = [];
            foreach ($rawUuids as $uuid) {
                $uuid = trim((string) $uuid);
                if ($uuid !== ''
                    && isset($allowedUuids[$uuid])
                    && !in_array($uuid, $uuids, true)
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)
                ) {
                    $uuids[] = $uuid;
                    if (count($uuids) >= self::MAX_ITEM_UUIDS) {
                        break;
                    }
                }
            }
            if ($uuids !== []) {
                $entry['uuids'] = $uuids;
            }

            $clean[] = $entry;
            if (count($clean) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $clean;
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
                'items' => [
                    'type' => 'array',
                    'description' => 'De 0 a 6 items estructurados: lo destacable/accionable del parte, uno por asunto. Sin HTML.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => [
                                'type' => 'string',
                                'enum' => self::ITEM_KINDS,
                                'description' => 'Categoría del item.',
                            ],
                            'severity' => [
                                'type' => 'string',
                                'enum' => self::ITEM_SEVERITIES,
                                'description' => 'Semáforo: exito (estimaciones), aviso (plazos que corren), fallo (silencio/inadmisión/denegación), curso (trámite normal), neutro (caducidades, recordatorios).',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Titular de la fila de acción, ≤120 caracteres: «Reclamación estimada — exige la entrega». Si el item agrupa varias, incluye la cifra: «3 solicitudes en silencio administrativo».',
                            ],
                            'detail' => [
                                'type' => 'string',
                                'description' => 'Contexto breve: solicitud · organismo · dato clave.',
                            ],
                            'uuids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'UUIDs de las solicitudes del item (de los que aparecen entre llaves en el input). En items agrupados, TODAS las del grupo. Vacío solo si el item no refiere solicitudes concretas.',
                            ],
                            'action' => [
                                'type' => 'string',
                                'description' => 'Verbo corto de la acción: «Ver resolución», «Redactar alegaciones», «Reclamar», «Valorar», «Reactivar».',
                            ],
                        ],
                        'required' => ['kind', 'severity', 'title'],
                    ],
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
            'required' => ['summary', 'items', 'references'],
        ];
    }

    /**
     * @param UserNotification[]                       $notifications
     * @param list<array<string, mixed>>               $upcoming      Alerts from UpcomingDeadlineCollector.
     */
    private function buildPrompt(User $user, array $notifications, array $upcoming): CompiledPrompt
    {
        $context = sprintf(
            'Usuario: %s. Total de eventos en la ventana: %d.',
            $user->getFullName(),
            count($notifications),
        );

        $lines = [];
        foreach ($notifications as $n) {
            $req = $n->getAccessRequest();
            // «YA RECLAMADA»: el modelo no debe sugerir reclamar lo que ya
            // está en vía de reclamación (el silencio reclamado deja de ser
            // "reclamable", aunque el estado siga siendo silencio).
            $reqLabel = $req !== null
                ? sprintf(
                    '{%s} "%s" (%s)%s',
                    $req->getId(),
                    $req->getTitle(),
                    $req->getPublicBody()?->getName() ?? 'organismo desconocido',
                    $req->hasActiveComplaint() ? ' · YA RECLAMADA' : '',
                )
                : 'sin solicitud asociada';

            $lines[] = sprintf(
                '- [%s] %s · solicitud %s · "%s"',
                $n->getCreatedAt()->format('d/m H:i'),
                $n->getTypeLabel(),
                $reqLabel,
                trim((string) $n->getMessage()),
            );
        }

        // "Lo que viene": the same alerts the DeadlineAlerts card shows, so the
        // closing sentence and the dashboard talk about identical deadlines.
        $upcomingLines = [];
        foreach ($upcoming as $alert) {
            $upcomingLines[] = sprintf(
                '- [vence %s] %s · solicitud {%s} "%s" (%s)',
                $alert['deadlineAt']->format('d/m'),
                $alert['message'],
                $alert['id'],
                $alert['title'],
                $alert['publicBody'],
            );
        }

        return $this->promptStore->compile('pideinfo-activity-summary-24h', [
            'user_context' => $context,
            'notifications_block' => implode("\n", $lines),
            'upcoming_block' => $upcomingLines === []
                ? '(No hay plazos en los próximos días.)'
                : implode("\n", $upcomingLines),
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
        $html = trim(strip_tags($html, ['b', 'i']));

        // strip_tags keeps ATTRIBUTES on allowed tags — and the model has been
        // seen emitting things like <b style='color:red;'>. Attributes on
        // LLM-authored tags rendered with |raw are an XSS surface (style, on*
        // handlers), so the surviving <b>/<i> are reduced to their bare form.
        return (string) preg_replace('/<(b|i)\b[^>]*>/i', '<$1>', $html);
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
