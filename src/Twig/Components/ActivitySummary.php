<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\UserNotificationRepository;
use App\Service\ActivitySummary\ActivitySummarizer;
use App\Service\ActivitySummary\ActivitySummaryWarmer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Renders the AI-generated 1–2 paragraph summary of the user's last 24 h
 * activity at the top of the home dashboard.
 *
 * The summary itself is pre-computed by `WarmActivitySummaryHandler` and
 * persisted on the User row, so the render is instant and never blocks on an
 * LLM call. When the cache fingerprint diverges from the current state, we
 * trigger a warm and ask the LiveComponent to poll until it lands.
 */
#[AsLiveComponent('ActivitySummary')]
final class ActivitySummary extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly Security $security,
        private readonly UserNotificationRepository $notificationRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ActivitySummarizer $summarizer,
        private readonly ActivitySummaryWarmer $warmer,
    ) {
    }

    public function getUser(): ?User
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        return $user;
    }

    public function getCachedHtml(): ?string
    {
        return $this->getUser()?->getActivitySummaryHtml();
    }

    public function getCachedAt(): ?\DateTimeImmutable
    {
        return $this->getUser()?->getActivitySummaryUpdatedAt();
    }

    public function getNotificationCount(): int
    {
        $user = $this->getUser();
        if ($user === null) {
            return 0;
        }
        return count($this->notificationRepository->findSinceByUser($user, new \DateTimeImmutable('-24 hours')));
    }

    /**
     * `true` when the persisted summary doesn't reflect the latest 24 h state.
     * Triggers a background warm and tells the template to poll for updates.
     */
    public function isStale(): bool
    {
        $user = $this->getUser();
        if ($user === null) {
            return false;
        }

        $notifications = $this->notificationRepository->findSinceByUser($user, new \DateTimeImmutable('-24 hours'));
        $current = $notifications === [] ? null : $this->summarizer->fingerprint($user, $notifications);

        if ($current === $user->getActivitySummaryFingerprint()) {
            return false;
        }

        // Stale → kick off (debounced) regeneration so the next render lands fresh.
        $this->warmer->maybeWarm($user);
        return true;
    }

    /**
     * Whether the card should render at all. Hidden when there are no
     * notifications in the window AND no cache is sitting around either.
     */
    public function isVisible(): bool
    {
        return $this->getCachedHtml() !== null || $this->getNotificationCount() > 0;
    }

    /**
     * Structured items cached alongside the summary (sanitized by
     * ActivitySummarizer), each with its solicitudes resolved to entities so
     * the template can render direct links and the «Ver» dialog for groups.
     * One query for all items; ownership enforced by the user filter (the
     * sanitizer already whitelists, this is the second belt).
     *
     * @return list<array{item: array<string, mixed>, requests: list<AccessRequest>}>
     */
    public function getResolvedItems(): array
    {
        $user = $this->getUser();
        if ($user === null) {
            return [];
        }

        $items = $user->getActivitySummaryItems() ?? [];

        $allUuids = [];
        foreach ($items as $item) {
            foreach ((array) ($item['uuids'] ?? []) as $uuid) {
                $allUuids[(string) $uuid] = true;
            }
            // Cachés del formato anterior (uuid singular)
            if (isset($item['uuid'])) {
                $allUuids[(string) $item['uuid']] = true;
            }
        }

        $byId = [];
        if ($allUuids !== []) {
            foreach ($this->accessRequestRepository->findBy(['id' => array_keys($allUuids), 'user' => $user]) as $request) {
                $byId[(string) $request->getId()] = $request;
            }
        }

        $resolved = [];
        foreach ($items as $item) {
            $uuids = (array) ($item['uuids'] ?? []);
            if (isset($item['uuid'])) {
                $uuids[] = $item['uuid'];
            }
            $requests = [];
            foreach (array_unique(array_map('strval', $uuids)) as $uuid) {
                if (isset($byId[$uuid])) {
                    $requests[] = $byId[$uuid];
                }
            }
            $resolved[] = ['item' => $item, 'requests' => $requests];
        }

        return $resolved;
    }

    /**
     * Humanized "hace X" for the cached-at timestamp. Returns null when there is
     * no cache yet.
     */
    public function getCachedAgo(): ?string
    {
        $at = $this->getCachedAt();
        if ($at === null) {
            return null;
        }

        $diff = (new \DateTimeImmutable())->getTimestamp() - $at->getTimestamp();
        if ($diff < 60) {
            return 'hace unos segundos';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return sprintf('hace %d %s', $m, $m === 1 ? 'minuto' : 'minutos');
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return sprintf('hace %d %s', $h, $h === 1 ? 'hora' : 'horas');
        }
        return 'hace más de un día';
    }
}
