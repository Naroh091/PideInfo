<?php

declare(strict_types=1);

namespace App\Service\Anonymous;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks which anonymous drafts (ownerless AccessRequests created from the
 * public /redactar flow) belong to the current visitor. The session is the
 * ONLY link between visitor and draft until AnonymousDraftClaimer assigns the
 * drafts to a freshly registered / logged-in user. Session attributes survive
 * the login session-id migration, so the ids are still readable at claim time.
 */
final class AnonymousDraftSessionStore
{
    private const SESSION_KEY = 'anon_draft_ids';

    /** Draft the visitor wanted to submit when they hit register/login. */
    private const INTENT_KEY = 'anon_submit_intent';

    /** Max simultaneous anonymous drafts per session (soft anti-abuse cap). */
    public const MAX_DRAFTS = 3;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function add(Uuid $id): void
    {
        $ids = $this->all();
        $rfc = $id->toRfc4122();
        if (!in_array($rfc, $ids, true)) {
            $ids[] = $rfc;
        }
        $this->requestStack->getSession()->set(self::SESSION_KEY, $ids);
    }

    public function contains(Uuid $id): bool
    {
        return in_array($id->toRfc4122(), $this->all(), true);
    }

    public function isFull(): bool
    {
        return count($this->all()) >= self::MAX_DRAFTS;
    }

    /**
     * @return list<string> RFC-4122 UUID strings
     */
    public function all(): array
    {
        try {
            $ids = $this->requestStack->getSession()->get(self::SESSION_KEY, []);
        } catch (\LogicException) {
            return []; // no session available (CLI, messenger worker…)
        }

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    public function clear(): void
    {
        try {
            $this->requestStack->getSession()->remove(self::SESSION_KEY);
        } catch (\LogicException) {
            // no session: nothing to clear
        }
    }

    /**
     * Remembers that the visitor reached the send page for this draft, so the
     * post-claim login listener can land them straight on the claimed
     * request/complaint instead of the default redirect.
     */
    public function rememberSubmitIntent(Uuid $id, string $flow): void
    {
        try {
            $this->requestStack->getSession()->set(self::INTENT_KEY, [
                'id' => $id->toRfc4122(),
                'flow' => $flow,
            ]);
        } catch (\LogicException) {
            // no session: nothing to remember
        }
    }

    /**
     * @return array{id: string, flow: string}|null Removed from the session on
     *                                              read (one-shot).
     */
    public function consumeSubmitIntent(): ?array
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (\LogicException) {
            return null;
        }

        $intent = $session->get(self::INTENT_KEY);
        $session->remove(self::INTENT_KEY);

        if (!is_array($intent) || !is_string($intent['id'] ?? null) || !is_string($intent['flow'] ?? null)) {
            return null;
        }

        return ['id' => $intent['id'], 'flow' => $intent['flow']];
    }
}
