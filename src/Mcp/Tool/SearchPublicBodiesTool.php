<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Mcp\Dto\PublicBodySubmittableSummary;
use App\Repository\PublicBodyRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\ChannelResolver;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Searches public bodies a request can actually be submitted to (AGE Portal de
 * Transparencia or REG / RED SARA), enriched with the submission channel,
 * whether a DIR3 destination is required, and the resolved applicable law.
 */
#[McpTool(
    name: 'search_public_bodies',
    description: 'Busca organismos públicos a los que se puede dirigir una solicitud, indicando el canal (Portal de Transparencia o REG), si requieren elegir una unidad DIR3 (requiresRegDestination) y la ley aplicable. Usa el id como publicBodyId en start_request_draft.',
)]
final class SearchPublicBodiesTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly ChannelResolver $channelResolver,
        private readonly ApplicableLawResolver $applicableLawResolver,
    ) {
    }

    /**
     * @param string      $query      Texto a buscar en el nombre del organismo.
     * @param string|null $nivel      Nivel administrativo opcional (estatal, autonomico, local…).
     * @param string|null $ministerio Ministerio (para filtrar dentro del nivel estatal).
     * @param string|null $comunidad  Comunidad autónoma (para filtrar el nivel autonómico).
     * @param int         $limit      Máximo de resultados (1-50, por defecto 20).
     *
     * @return array{bodies: list<PublicBodySubmittableSummary>, count: int}
     */
    public function __invoke(
        string $query = '',
        ?string $nivel = null,
        ?string $ministerio = null,
        ?string $comunidad = null,
        int $limit = 20,
    ): array {
        $this->tokenContext->requireScope('mcp:read');
        $this->requireUser();

        $limit = max(1, min(50, $limit));
        $bodies = $this->publicBodyRepository->searchSubmittable($query, $nivel, $ministerio, $comunidad, $limit);

        $summaries = [];
        foreach ($bodies as $body) {
            $taskType = $this->channelResolver->resolveTaskType($body);
            $law = $this->applicableLawResolver->resolveFor($body);
            $summaries[] = PublicBodySubmittableSummary::fromEntity(
                body: $body,
                taskType: $taskType,
                channelLabel: $this->channelLabel($taskType),
                requiresRegDestination: $taskType === AgentTask::TYPE_SUBMIT_REQUEST_REG,
                applicableLawId: $law?->getId()?->toRfc4122(),
                applicableLaw: $law?->getName(),
            );
        }

        return ['bodies' => $summaries, 'count' => count($summaries)];
    }

    private function channelLabel(string $taskType): string
    {
        return match ($taskType) {
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL => 'Portal de Transparencia',
            AgentTask::TYPE_SUBMIT_REQUEST_REG => 'REG / RED SARA',
            default => $taskType,
        };
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
