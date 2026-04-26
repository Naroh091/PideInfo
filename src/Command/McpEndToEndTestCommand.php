<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Entity\AccessToken as LeagueAccessTokenEntity;
use League\Bundle\OAuth2ServerBundle\Entity\Client as LeagueClientEntity;
use League\Bundle\OAuth2ServerBundle\Entity\Scope as LeagueScopeEntity;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken as AccessTokenModel;
use League\Bundle\OAuth2ServerBundle\Model\Client as ClientModel;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope as ScopeValueObject;
use League\OAuth2\Server\CryptKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Smoke-test the MCP HTTP endpoint end-to-end inside the booted kernel.
 *
 * The command bypasses the user-facing PKCE handshake by minting an OAuth2
 * access token directly through the league bundle's persistence + signing
 * primitives — what we want to verify is the resource-server side: the bearer
 * is accepted by the MCP firewall, OAuth2TokenHandler resolves the user, and
 * the registered tools execute under that identity.
 */
#[AsCommand(
    name: 'app:mcp:e2e-test',
    description: 'Mints an OAuth2 access token for a user and exercises /mcp (initialize, tools/list, tools/call).',
)]
final class McpEndToEndTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientManagerInterface $clientManager,
        private readonly AccessTokenManagerInterface $accessTokenManager,
        private readonly HttpKernelInterface $kernel,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(env: 'OAUTH_PRIVATE_KEY')]
        private readonly string $privateKeyPath,
        #[Autowire(env: 'OAUTH_PASSPHRASE')]
        private readonly string $privateKeyPassphrase,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-email', null, InputOption::VALUE_REQUIRED, 'Email of the user to impersonate; defaults to the first active user.')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Existing OAuth2 client identifier to use; created if missing.', 'mcp-e2e')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Space-separated scopes for the minted token.', 'mcp:read mcp:write mcp:documents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->resolveUser((string) ($input->getOption('user-email') ?? ''));
        if (null === $user) {
            $io->error('No active user found. Pass --user-email or seed a test user first.');

            return Command::FAILURE;
        }
        $io->text(\sprintf('Impersonating user <info>%s</info> (id=%s)', $user->getEmail(), $user->getId()->toRfc4122()));

        $client = $this->resolveClient((string) $input->getOption('client-id'));
        $io->text(\sprintf('Using OAuth2 client <info>%s</info>', $client->getIdentifier()));

        $scopes = array_filter(explode(' ', (string) $input->getOption('scopes')));
        $jwt = $this->mintAccessToken($user, $client, $scopes);
        $io->text(\sprintf('Minted JWT (length=%d)', strlen($jwt)));

        $checks = [
            'initialize' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'pideinfo-e2e', 'version' => '1.0'],
                ],
            ],
            'tools/list' => [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ],
            'tools/call search_requests' => [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => 'search_requests', 'arguments' => ['limit' => 3]],
            ],
            'tools/call list_public_bodies' => [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => ['name' => 'list_public_bodies', 'arguments' => ['query' => 'transparencia', 'limit' => 3]],
            ],
            'tools/call list_upcoming_deadlines' => [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'tools/call',
                'params' => ['name' => 'list_upcoming_deadlines', 'arguments' => ['daysAhead' => 30]],
            ],
        ];

        $sessionId = null;
        $hasFailures = false;
        foreach ($checks as $label => $payload) {
            $headers = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ];
            if (null !== $sessionId) {
                $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
            }

            $request = Request::create(
                uri: '/mcp',
                method: 'POST',
                server: $headers,
                content: json_encode($payload, JSON_THROW_ON_ERROR),
            );

            $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $body = $response->getContent();
            $status = $response->getStatusCode();
            $session = $response->headers->get('Mcp-Session-Id') ?? $response->headers->get('mcp-session-id');
            if (null !== $session && null === $sessionId) {
                $sessionId = $session;
            }

            $isOk = $status >= 200 && $status < 300;
            $io->section(\sprintf('%s → HTTP %d', $label, $status));
            $io->writeln($this->summarise($body));
            if (!$isOk) {
                $hasFailures = true;
            }
        }

        if ($hasFailures) {
            $io->error('One or more probes failed.');

            return Command::FAILURE;
        }

        $io->success('MCP endpoint is reachable and tools execute under the OAuth2 identity.');

        return Command::SUCCESS;
    }

    private function resolveUser(string $email): ?User
    {
        $repo = $this->em->getRepository(User::class);
        if ('' !== $email) {
            return $repo->findOneBy(['email' => $email, 'isActive' => true]);
        }

        return $repo->findOneBy(['isActive' => true], ['createdAt' => 'ASC']);
    }

    private function resolveClient(string $clientId): ClientModel
    {
        $existing = $this->clientManager->find($clientId);
        if ($existing instanceof ClientModel) {
            return $existing;
        }

        $client = new ClientModel('MCP E2E Test', $clientId, null);
        $client->setActive(true);
        $client->setRedirectUris(new RedirectUri('https://example.com/cb'));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(
            new ScopeValueObject('mcp:read'),
            new ScopeValueObject('mcp:write'),
            new ScopeValueObject('mcp:documents'),
        );
        $this->clientManager->save($client);

        return $client;
    }

    /**
     * @param list<string> $scopeNames
     */
    private function mintAccessToken(User $user, ClientModel $client, array $scopeNames): string
    {
        $tokenIdentifier = bin2hex(random_bytes(40));
        $expiry = new \DateTimeImmutable('+1 hour');
        $userIdentifier = $user->getId()->toRfc4122();

        $clientEntity = new LeagueClientEntity();
        $clientEntity->setName($client->getName());
        $clientEntity->setIdentifier($client->getIdentifier());
        $clientEntity->setRedirectUri(array_map('strval', $client->getRedirectUris()));
        $clientEntity->setConfidential($client->isConfidential());

        $accessTokenEntity = new LeagueAccessTokenEntity();
        $accessTokenEntity->setIdentifier($tokenIdentifier);
        $accessTokenEntity->setClient($clientEntity);
        $accessTokenEntity->setUserIdentifier($userIdentifier);
        $accessTokenEntity->setExpiryDateTime($expiry);
        foreach ($scopeNames as $name) {
            $scope = new LeagueScopeEntity();
            $scope->setIdentifier($name);
            $accessTokenEntity->addScope($scope);
        }

        $privateKeyPath = $this->resolvePath($this->privateKeyPath);
        $passphrase = '' === $this->privateKeyPassphrase ? null : $this->privateKeyPassphrase;
        $accessTokenEntity->setPrivateKey(new CryptKey($privateKeyPath, $passphrase, false));

        // Persist the matching record so the ResourceServer revocation check passes.
        $persisted = new AccessTokenModel(
            $tokenIdentifier,
            $expiry,
            $client,
            $userIdentifier,
            array_map(static fn (string $s) => new ScopeValueObject($s), $scopeNames),
        );
        $this->accessTokenManager->save($persisted);

        return $accessTokenEntity->toString();
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '%kernel.project_dir%')) {
            return $this->projectDir.substr($path, \strlen('%kernel.project_dir%'));
        }

        return $path;
    }

    private function summarise(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return mb_substr($body, 0, 400);
        }

        if (isset($decoded['result']['tools']) && is_array($decoded['result']['tools'])) {
            $names = array_map(static fn ($t) => $t['name'] ?? '?', $decoded['result']['tools']);

            return 'tools: '.implode(', ', $names);
        }

        return mb_substr(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 600);
    }
}
