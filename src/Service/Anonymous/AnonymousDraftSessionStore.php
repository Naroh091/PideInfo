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
}
