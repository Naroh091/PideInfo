<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Mcp\Dto\DeadlineEntry;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * List the user's requests with deadlines falling within the next N days, including overdue ones.
 */
#[McpTool(
    name: 'list_upcoming_deadlines',
    description: 'Lista las solicitudes del usuario cuyo plazo expira en los próximos N días o ya está vencido, ordenadas por urgencia.',
)]
final class ListUpcomingDeadlinesTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    /**
     * @param int $daysAhead Window in days from today (default 30; max 365).
     *
     * @return array{deadlines: list<DeadlineEntry>, count: int, daysAhead: int}
     */
    public function __invoke(int $daysAhead = 30): array
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        $daysAhead = max(1, min(365, $daysAhead));
        $now = new \DateTimeImmutable('today');
        $until = $now->modify(\sprintf('+%d days', $daysAhead));

        // Pull recent open requests; filter and sort in PHP to stay independent
        // of repository SQL changes. Limit cap is generous because we slice client-side.
        $candidates = $this->accessRequestRepository->findByUser($user, null, 1, 200);

        $entries = [];
        foreach ($candidates as $request) {
            assert($request instanceof AccessRequest);
            $deadline = $request->getDeadlineAt();
            if (null === $deadline || $deadline > $until) {
                continue;
            }
            if (in_array($request->getStatus(), [AccessRequest::STATUS_FINISHED], true)) {
                continue;
            }
            $entries[] = DeadlineEntry::fromEntity($request, $now);
        }

        usort($entries, static fn (DeadlineEntry $a, DeadlineEntry $b) => $a->daysRemaining <=> $b->daysRemaining);

        return [
            'deadlines' => $entries,
            'count' => count($entries),
            'daysAhead' => $daysAhead,
        ];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
