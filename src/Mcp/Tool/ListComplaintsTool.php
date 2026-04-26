<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequestComplaint;
use App\Entity\User;
use App\Mcp\Dto\ComplaintSummary;
use App\Repository\AccessRequestComplaintRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * List the authenticated user's complaints (reclamaciones), optionally filtered by status.
 */
#[McpTool(
    name: 'list_complaints',
    description: 'Lista las reclamaciones (recursos al CTBG/órgano autonómico) del usuario, opcionalmente filtradas por estado.',
)]
final class ListComplaintsTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestComplaintRepository $complaintRepository,
    ) {
    }

    /**
     * @param string|null $status Optional filter: reclaimed, complaint_granted, complaint_denied, complaint_archived.
     * @param int         $limit  Maximum results (1-100, default 20).
     * @param int         $page   1-based page number.
     *
     * @return array{complaints: list<ComplaintSummary>, page: int, limit: int, count: int}
     */
    public function __invoke(?string $status = null, int $limit = 20, int $page = 1): array
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if ($status !== null && !in_array($status, AccessRequestComplaint::STATUSES, true)) {
            throw new InvalidArgumentException(\sprintf('Invalid status "%s".', $status));
        }

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        $entities = $this->complaintRepository->findByUser($user, $status, $page, $limit);
        $summaries = array_map(static fn ($c) => ComplaintSummary::fromEntity($c), $entities);

        return [
            'complaints' => $summaries,
            'page' => $page,
            'limit' => $limit,
            'count' => count($summaries),
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
