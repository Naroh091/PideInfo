<?php

declare(strict_types=1);

namespace App\Controller\OAuth2;

use App\Entity\DynamicClientMetadata;
use App\Entity\User;
use App\Repository\DynamicClientMetadataRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken as OAuth2AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken as OAuth2RefreshToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectedAppsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ClientManagerInterface $clientManager,
        private readonly DynamicClientMetadataRepository $metadataRepository,
    ) {
    }

    #[Route('/perfil/aplicaciones-conectadas', name: 'app_connected_apps', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userIdentifier = $user->getId()->toRfc4122();

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'at.client AS client_id',
                'COUNT(at.identifier) AS active_tokens',
                'MAX(at.expiry) AS last_expiry',
                'MIN(at.expiry) AS earliest_expiry',
            )
            ->from('oauth2_access_token', 'at')
            ->where('at.user_identifier = :uid')
            ->andWhere('at.revoked = :notRevoked')
            ->andWhere('at.expiry > :now')
            ->groupBy('at.client')
            ->setParameter('uid', $userIdentifier)
            ->setParameter('notRevoked', false, \Doctrine\DBAL\ParameterType::BOOLEAN)
            ->setParameter('now', (new \DateTimeImmutable())->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        $apps = [];
        foreach ($rows as $row) {
            $clientId = (string) $row['client_id'];
            $client = $this->clientManager->find($clientId);
            if (null === $client) {
                continue;
            }
            $metadata = $this->metadataRepository->find($clientId);
            $apps[] = [
                'clientId' => $clientId,
                'displayName' => $metadata?->getClientName() ?? $client->getName() ?? $clientId,
                'logoUri' => $metadata?->getLogoUri(),
                'clientUri' => $metadata?->getClientUri(),
                'isDynamic' => $metadata?->isDynamic() ?? false,
                'activeTokens' => (int) $row['active_tokens'],
                'lastExpiry' => new \DateTimeImmutable((string) $row['last_expiry']),
            ];
        }

        return $this->render('panel/connected_apps.html.twig', [
            'apps' => $apps,
            'agent' => [
                'connected' => $user->isAgentConnected(),
                'issuedAt' => $user->getAgentTokenIssuedAt(),
                'invalidatedAt' => $user->getAgentTokensInvalidatedAt(),
            ],
        ]);
    }

    #[Route('/perfil/aplicaciones-conectadas/oauth2/{clientId}/revoke', name: 'app_connected_apps_revoke_oauth', methods: ['POST'])]
    public function revokeOAuthClient(Request $request, string $clientId): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('connected-apps-revoke-'.$clientId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');

            return $this->redirectToRoute('app_connected_apps');
        }

        /** @var User $user */
        $user = $this->getUser();
        $userIdentifier = $user->getId()->toRfc4122();

        $this->em->wrapInTransaction(function () use ($userIdentifier, $clientId): void {
            $accessTokens = $this->em->createQueryBuilder()
                ->select('at')
                ->from(OAuth2AccessToken::class, 'at')
                ->where('at.userIdentifier = :uid')
                ->andWhere('IDENTITY(at.client) = :cid')
                ->andWhere('at.revoked = false')
                ->setParameter('uid', $userIdentifier)
                ->setParameter('cid', $clientId)
                ->getQuery()
                ->getResult();

            foreach ($accessTokens as $token) {
                /** @var OAuth2AccessToken $token */
                $token->revoke();

                $refreshTokens = $this->em->createQueryBuilder()
                    ->select('rt')
                    ->from(OAuth2RefreshToken::class, 'rt')
                    ->where('IDENTITY(rt.accessToken) = :tid')
                    ->andWhere('rt.revoked = false')
                    ->setParameter('tid', $token->getIdentifier())
                    ->getQuery()
                    ->getResult();

                foreach ($refreshTokens as $rt) {
                    /** @var OAuth2RefreshToken $rt */
                    $rt->revoke();
                }
            }

            $this->em->flush();
        });

        $this->addFlash('success', 'Aplicación desconectada correctamente.');

        return $this->redirectToRoute('app_connected_apps');
    }

    #[Route('/perfil/aplicaciones-conectadas/agent/revoke', name: 'app_connected_apps_revoke_agent', methods: ['POST'])]
    public function revokeAgent(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('connected-apps-revoke-agent', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');

            return $this->redirectToRoute('app_connected_apps');
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setAgentTokensInvalidatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', 'Token del agente revocado. Genera uno nuevo desde la página "Agente" si quieres reconectarlo.');

        return $this->redirectToRoute('app_connected_apps');
    }
}
