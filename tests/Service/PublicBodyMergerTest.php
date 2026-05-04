<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PublicBody;
use App\Entity\Resolution;
use App\Service\PublicBodyMerger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class PublicBodyMergerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private PublicBodyMerger $merger;

    /** @var list<string> Tracks created rows for cleanup. */
    private array $createdPublicBodyIds = [];
    /** @var list<string> */
    private array $createdResolutionIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->conn = $container->get(Connection::class);
        $this->merger = $container->get(PublicBodyMerger::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdResolutionIds as $id) {
            $this->conn->executeStatement('DELETE FROM resolution WHERE id = :id', ['id' => $id]);
        }
        foreach ($this->createdPublicBodyIds as $id) {
            $this->conn->executeStatement('DELETE FROM public_body WHERE id = :id', ['id' => $id]);
        }
        parent::tearDown();
    }

    public function testMergeRepointsResolutionFkAndDeletesLosers(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $a = $this->makeBody("Body A {$suffix}");
        $a->setEmail('a@example.com');
        $b = $this->makeBody("Body B {$suffix}");
        $b->setRegistryCode("REG-{$suffix}");
        $c = $this->makeBody("Body C {$suffix}");

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $this->createdPublicBodyIds[] = $a->getId()->toRfc4122();
        $this->createdPublicBodyIds[] = $b->getId()->toRfc4122();
        $this->createdPublicBodyIds[] = $c->getId()->toRfc4122();

        // Insert a Resolution pointing at C, with publicBodyName matching C's name.
        $resolutionId = Uuid::v7()->toRfc4122();
        $this->conn->insert('resolution', [
            'id' => $resolutionId,
            'reference_number' => "REF-{$suffix}",
            'outcome' => Resolution::OUTCOME_FAVORABLE,
            'summary' => 'test summary',
            'full_text' => 'test full text',
            'source' => Resolution::SOURCE_CTBG,
            'public_body_name' => $c->getName(),
            'public_body_id' => $c->getId()->toRfc4122(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->createdResolutionIds[] = $resolutionId;

        // Pre-apply field choices to the survivor (controller is responsible for this in the real flow).
        $a->setRegistryCode($b->getRegistryCode());

        $result = $this->merger->merge($a, [$b, $c]);

        self::assertSame(1, $result->affectedResolutions, 'one resolution should be re-pointed');
        self::assertCount(2, $result->deletedIds, 'two losers should be deleted');

        // Loser rows are gone.
        $remaining = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM public_body WHERE id IN (:ids)',
            ['ids' => [$b->getId()->toRfc4122(), $c->getId()->toRfc4122()]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        self::assertSame(0, $remaining);

        // Survivor still there with the picked registry code.
        $survivorRow = $this->conn->fetchAssociative(
            'SELECT registry_code, email FROM public_body WHERE id = :id',
            ['id' => $a->getId()->toRfc4122()],
        );
        self::assertSame("REG-{$suffix}", $survivorRow['registry_code']);
        self::assertSame('a@example.com', $survivorRow['email']);

        // Resolution now points at survivor and was renamed to survivor's name.
        $resolutionRow = $this->conn->fetchAssociative(
            'SELECT public_body_id, public_body_name FROM resolution WHERE id = :id',
            ['id' => $resolutionId],
        );
        self::assertSame($a->getId()->toRfc4122(), $resolutionRow['public_body_id']);
        self::assertSame($a->getName(), $resolutionRow['public_body_name']);
    }

    public function testPreviewImpactCountsAffectedRows(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $survivor = $this->makeBody("Survivor {$suffix}");
        $loser = $this->makeBody("Loser {$suffix}");
        $this->em->persist($survivor);
        $this->em->persist($loser);
        $this->em->flush();
        $this->createdPublicBodyIds[] = $survivor->getId()->toRfc4122();
        $this->createdPublicBodyIds[] = $loser->getId()->toRfc4122();

        $resolutionId = Uuid::v7()->toRfc4122();
        $this->conn->insert('resolution', [
            'id' => $resolutionId,
            'reference_number' => "REF-PI-{$suffix}",
            'outcome' => Resolution::OUTCOME_FAVORABLE,
            'summary' => 'preview test',
            'full_text' => 'preview test',
            'source' => Resolution::SOURCE_CTBG,
            'public_body_id' => $loser->getId()->toRfc4122(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->createdResolutionIds[] = $resolutionId;

        $preview = $this->merger->previewImpact($survivor, [$loser]);

        self::assertSame(0, $preview->accessRequestsPrimary);
        self::assertSame(0, $preview->accessRequestsOriginal);
        self::assertSame(1, $preview->resolutions);
    }

    public function testMergeWithEmptyLosersIsNoop(): void
    {
        $body = $this->makeBody('Body Solo ' . bin2hex(random_bytes(4)));
        $this->em->persist($body);
        $this->em->flush();
        $this->createdPublicBodyIds[] = $body->getId()->toRfc4122();

        $result = $this->merger->merge($body, []);

        self::assertSame(0, $result->affectedAccessRequests);
        self::assertSame(0, $result->affectedResolutions);
        self::assertSame([], $result->deletedIds);
    }

    public function testMergeRejectsSurvivorAsLoser(): void
    {
        $body = $this->makeBody('Body Self ' . bin2hex(random_bytes(4)));
        $this->em->persist($body);
        $this->em->flush();
        $this->createdPublicBodyIds[] = $body->getId()->toRfc4122();

        $this->expectException(\InvalidArgumentException::class);
        $this->merger->merge($body, [$body]);
    }

    private function makeBody(string $name): PublicBody
    {
        $body = new PublicBody();
        $body->setName($name);
        $body->setLevel(PublicBody::LEVEL_STATE);
        return $body;
    }
}
