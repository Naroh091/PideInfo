<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Batch;

use App\Billing\EffectivePlanResolver;
use App\Billing\PlanCatalog;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AI\Chat\AssistantTurnStore;

/**
 * Single classifier of where each recipient of a multi-recipient batch stands
 * relative to the unified template. The batch navigation (tabs), the template
 * page's "Versiones por organismo" panel and the generation endpoint all read
 * these states — never re-derive them in Twig or JS.
 *
 * States:
 *   - sent        → no longer a draft (dispatched).
 *   - empty       → nothing written yet.
 *   - template    → generated from the current template, untouched since.
 *   - adjusted    → generated from the template, then edited (by hand or with
 *                   its own assistant thread). Regenerating would overwrite it.
 *   - independent → written without the template.
 * Orthogonal flags:
 *   - outdated    → generated from an older version of the template.
 *   - working     → its assistant is mid-turn right now.
 */
class DraftBatchOverview
{
    public const STATE_SENT = 'sent';
    public const STATE_EMPTY = 'empty';
    public const STATE_TEMPLATE = 'template';
    public const STATE_ADJUSTED = 'adjusted';
    public const STATE_INDEPENDENT = 'independent';

    private const LABELS = [
        self::STATE_SENT => 'Enviada',
        self::STATE_EMPTY => 'Sin redactar',
        self::STATE_TEMPLATE => 'Desde la plantilla',
        self::STATE_ADJUSTED => 'Ajustada a mano',
        self::STATE_INDEPENDENT => 'Redactada aparte',
    ];

    private const THREAD_KEY = 'draft_chat_history';

    public function __construct(
        private readonly AssistantTurnStore $turnStore,
        private readonly AccessRequestRepository $accessRequests,
        private readonly DraftBatchTemplateStore $templateStore,
        private readonly EffectivePlanResolver $planResolver,
        private readonly PlanCatalog $planCatalog,
    ) {
    }

    /**
     * The drafts of a batch the user owns, oldest first. The first one hosts
     * the template chat's turn record. Team-shared drafts of someone else's
     * batch are left out: the template and its versions are the owner's work.
     *
     * @return list<AccessRequest>
     */
    public function ownedSiblings(string $batchId, User $user): array
    {
        return array_values(array_filter(
            $this->accessRequests->findByDraftBatch($batchId, $user),
            static fn (AccessRequest $ar) => $ar->getUser()?->getId()->equals($user->getId()) === true,
        ));
    }

    /**
     * Everything the batch navigation needs, or null when the batch has a
     * single recipient (no template flow then).
     *
     * @param list<AccessRequest> $siblings
     *
     * `planName` is the effective plan that allows the multi-recipient batch,
     * shown next to the crown in the batch navigation.
     *
     * @return array{id: string, template: DraftBatchTemplate, rows: list<array<string, mixed>>, total: int, ready: int, generated: int, outdated: int, planName: string}|null
     */
    public function navigation(string $batchId, array $siblings, User $user): ?array
    {
        if (count($siblings) < 2) {
            return null;
        }

        $template = $this->templateStore->find($batchId, $user);
        $rows = $this->rows($siblings, $template);

        return [
            'id' => $batchId,
            'template' => $template,
            'rows' => $rows,
            'total' => count($rows),
            'ready' => count(array_filter($rows, static fn (array $r) => $r['ready'])),
            'generated' => count(array_filter($rows, static fn (array $r) => in_array($r['state'], [self::STATE_TEMPLATE, self::STATE_ADJUSTED], true))),
            'outdated' => count(array_filter($rows, static fn (array $r) => $r['outdated'])),
            'planName' => $this->planCatalog->get($this->planResolver->forUser($user))->name,
        ];
    }

    /**
     * @param list<AccessRequest> $siblings
     *
     * @return list<array{request: AccessRequest, state: string, label: string, outdated: bool, working: bool, ready: bool}>
     */
    public function rows(array $siblings, DraftBatchTemplate $template): array
    {
        $rows = [];
        foreach ($siblings as $ar) {
            $rows[] = $this->row($ar, $template);
        }

        return $rows;
    }

    /**
     * @return array{request: AccessRequest, state: string, label: string, outdated: bool, working: bool, ready: bool}
     */
    public function row(AccessRequest $ar, DraftBatchTemplate $template): array
    {
        $state = self::classify($ar);
        $version = $ar->getMetadataValue(BatchVersionGenerator::METADATA_KEY);
        $outdated = in_array($state, [self::STATE_TEMPLATE, self::STATE_ADJUSTED], true)
            && is_array($version)
            && !$template->isEmpty()
            && ($version['template_fingerprint'] ?? null) !== $template->fingerprint();

        $turn = $state === self::STATE_SENT ? null : $this->turnStore->find($ar, self::THREAD_KEY);

        return [
            'request' => $ar,
            'state' => $state,
            'label' => self::LABELS[$state],
            'outdated' => $outdated,
            'working' => ($turn['status'] ?? null) === AssistantTurnStore::STATUS_RUNNING,
            'ready' => $state !== self::STATE_EMPTY
                && trim($ar->getTitle()) !== ''
                && trim((string) $ar->getDescription()) !== '',
        ];
    }

    public static function classify(AccessRequest $ar): string
    {
        if ($ar->getStatus() !== AccessRequest::STATUS_PENDING) {
            return self::STATE_SENT;
        }

        $hasText = trim($ar->getTitle()) !== ''
            || trim((string) $ar->getDescription()) !== ''
            || trim((string) $ar->getExpone()) !== ''
            || trim((string) $ar->getSolicita()) !== '';
        if (!$hasText) {
            return self::STATE_EMPTY;
        }

        $version = $ar->getMetadataValue(BatchVersionGenerator::METADATA_KEY);
        if (!is_array($version)) {
            return self::STATE_INDEPENDENT;
        }

        return ($version['content_fingerprint'] ?? null) === BatchVersionGenerator::contentFingerprint($ar)
            ? self::STATE_TEMPLATE
            : self::STATE_ADJUSTED;
    }

    /**
     * True when (re)generating would overwrite work the user did on that
     * version on purpose: an adjusted version, or one written without the
     * template. The UI asks before including those.
     */
    public static function regenerationOverwritesWork(string $state): bool
    {
        return in_array($state, [self::STATE_ADJUSTED, self::STATE_INDEPENDENT], true);
    }
}
