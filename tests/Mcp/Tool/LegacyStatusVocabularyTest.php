<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Mcp\Tool\SearchRequestsTool;
use App\Mcp\Tool\UpdateRequestStatusTool;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestManager;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tras el rediseño (posición vs. decisión), los clientes MCP siguen pudiendo
 * usar el vocabulario legacy: `update_request_status` con `denied` etc. se
 * traduce a posición `finished` + resolutionResult, y el filtro `status` de
 * `search_requests` con esos valores filtra por el eje resolución.
 */
final class LegacyStatusVocabularyTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    public function testUpdateWithLegacyDeniedSetsFinishedPlusResolution(): void
    {
        self::bootKernel();
        [$user, $request] = $this->seed(AccessRequest::STATUS_PROCESSING);

        $tool = $this->updateTool($user);
        $summary = $tool($request->getId()->toRfc4122(), 'denied', 'resolución denegatoria');

        self::assertSame(AccessRequest::STATUS_FINISHED, $request->getStatus());
        self::assertSame(AccessRequest::RESULT_DENIED, $request->getResolutionResult());
        self::assertNotNull($request->getResolvedAt());
        self::assertSame(AccessRequest::STATUS_FINISHED, $summary->status);
        self::assertSame('finished', $summary->internalState);
        self::assertSame('Finalizada', $summary->internalStateLabel);

        // Ambos cambios (resolución y posición) quedan auditados.
        $em = static::getContainer()->get('doctrine')->getManager();
        $types = array_map(
            static fn (StatusHistory $h) => $h->getStatusType(),
            $em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $request]),
        );
        self::assertContains(StatusHistory::TYPE_RESOLUTION, $types);
        self::assertContains(StatusHistory::TYPE_STATUS, $types);
    }

    public function testUpdateWithLegacyGrantedCompletedOnlyMovesPosition(): void
    {
        self::bootKernel();
        [$user, $request] = $this->seed(AccessRequest::STATUS_GRANTED);
        $request->setResolutionResult(AccessRequest::RESULT_PARTIALLY_GRANTED);

        $tool = $this->updateTool($user);
        $tool($request->getId()->toRfc4122(), 'granted_completed', null);

        self::assertSame(AccessRequest::STATUS_FINISHED, $request->getStatus());
        // La decisión previa (parcial) NO se pisa.
        self::assertSame(AccessRequest::RESULT_PARTIALLY_GRANTED, $request->getResolutionResult());
    }

    public function testSearchWithLegacyDeniedFiltersByResolution(): void
    {
        self::bootKernel();
        [$user, $denied] = $this->seed(AccessRequest::STATUS_FINISHED, AccessRequest::RESULT_DENIED);
        $this->seedFor($user, AccessRequest::STATUS_FINISHED, AccessRequest::RESULT_GRANTED);
        $this->seedFor($user, AccessRequest::STATUS_PROCESSING);

        $tool = new SearchRequestsTool(
            $this->securityFor($user),
            $this->tokenContextFor($user, ['mcp:read']),
            static::getContainer()->get(AccessRequestRepository::class),
        );

        $result = $tool(null, 'denied');

        self::assertSame(1, $result['count']);
        self::assertSame($denied->getId()->toRfc4122(), $result['requests'][0]->id);

        // Y el filtro por posición nueva sigue funcionando.
        $finished = $tool(null, 'finished');
        self::assertSame(2, $finished['count']);
    }

    /** @return array{0: User, 1: AccessRequest} */
    private function seed(string $status, ?string $resolutionResult = null): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setEmail('mcp-legacy+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Mcp');
        $user->setLastName('Legacy');
        $em->persist($user);
        $em->flush();
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return [$user, $this->seedFor($user, $status, $resolutionResult)];
    }

    private function seedFor(User $user, string $status, ?string $resolutionResult = null): AccessRequest
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Ayto MCP');
        $em->persist($body);
        $this->trackFixtureBody($body->getId()->toRfc4122());

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013');
        $law->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $request = new AccessRequest();
        $request->setUser($user);
        $request->setPublicBody($body);
        $request->setApplicableLaw($law);
        $request->setTitle('Solicitud MCP legacy');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-2 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));
        $request->setStatus($status);
        if ($resolutionResult !== null) {
            $request->setResolutionResult($resolutionResult);
        }
        $em->persist($request);
        $em->flush();
        $this->trackFixtureRequest($request->getId()->toRfc4122());

        return $request;
    }

    private function updateTool(User $user): UpdateRequestStatusTool
    {
        return new UpdateRequestStatusTool(
            $this->securityFor($user),
            $this->tokenContextFor($user, ['mcp:read', 'mcp:write']),
            static::getContainer()->get(AccessRequestRepository::class),
            static::getContainer()->get(AccessRequestManager::class),
            static::getContainer()->get(EntityManagerInterface::class),
        );
    }

    private function securityFor(User $user): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    /** @param list<string> $scopes */
    private function tokenContextFor(User $user, array $scopes): OAuthTokenContext
    {
        $context = new OAuthTokenContext();
        $context->set($user->getId()->toRfc4122(), 'test-client', 'token-id', $scopes);

        return $context;
    }
}
