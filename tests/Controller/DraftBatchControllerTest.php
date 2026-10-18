<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Service\AccessRequest\Batch\DraftBatchTemplateStore;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Multi-recipient drafting: per-recipient conversations survive tab switches,
 * and the unified template ("plantilla común") page, autosave and version
 * generation guards.
 */
final class DraftBatchControllerTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testDraftPageRendersTheConversationPersistedInChatHistoryStore(): void
    {
        $client = static::createClient();
        [$user, $batchId, $drafts] = $this->seedBatch('batch-history@example.com');

        // What the assistant writes for a logged-in user lives in ai_chat_messages,
        // not in AccessRequest.metadata. Switching tabs must not lose it.
        static::getContainer()->get(ChatHistoryStore::class)->save(
            ChatHistoryStore::threadIdForRequest($drafts[1]),
            $user,
            [
                ['role' => 'user', 'kind' => 'text', 'content' => 'Quiero los contratos menores de 2025'],
                ['role' => 'assistant', 'kind' => 'text', 'action' => 'reply', 'content' => '<p>¿De qué importe?</p>'],
            ],
        );

        $client->loginUser($user);
        $client->request('GET', '/solicitudes/nueva/realizar/redactar/' . $drafts[1]->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.conv-history', 'Quiero los contratos menores de 2025');
        self::assertSelectorTextContains('.conv-history', '¿De qué importe?');
        // The batch navigation offers the template and every recipient.
        self::assertSelectorExists('.batch-nav a[href$="/lote/' . $batchId . '/plantilla"]');
        self::assertSelectorCount(2, '.batch-nav [data-batch-tab]');
    }

    public function testTemplatePageAndAutosave(): void
    {
        $client = static::createClient();
        [$user, $batchId] = $this->seedBatch('batch-template@example.com');
        $client->loginUser($user);

        $client->request('GET', '/solicitudes/nueva/realizar/lote/' . $batchId . '/plantilla');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'plantilla común');
        self::assertSelectorCount(2, '[data-batch-versions-target="row"]');

        $client->request(
            'POST',
            '/solicitudes/nueva/realizar/lote/' . $batchId . '/plantilla/autoguardar',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Contratos de [[ORGANISMO]]', 'expone' => 'Expone', 'solicita' => 'Al amparo de [[LEY_APLICABLE]]']),
        );
        self::assertResponseIsSuccessful();

        $template = static::getContainer()->get(DraftBatchTemplateStore::class)->find($batchId, $user);
        self::assertSame('Contratos de [[ORGANISMO]]', $template->title);
        self::assertSame('Al amparo de [[LEY_APLICABLE]]', $template->solicita);
    }

    public function testVersionGenerationGuards(): void
    {
        $client = static::createClient();
        [$user, $batchId, $drafts] = $this->seedBatch('batch-guards@example.com');
        $client->loginUser($user);
        $url = '/solicitudes/nueva/realizar/lote/' . $batchId . '/versiones/' . $drafts[0]->getId();

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(422, 'sin plantilla no hay nada que adaptar');

        static::getContainer()->get(DraftBatchTemplateStore::class)->save($batchId, $user, 'Asunto', 'Expone', 'Solicita');
        $em = static::getContainer()->get('doctrine')->getManager();
        $draft = $em->getRepository(AccessRequest::class)->find($drafts[0]->getId());
        $draft->setTitle('Texto propio')->setDescription('Redactado sin plantilla');
        $em->flush();

        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(409, 'no sobrescribe una versión redactada aparte sin permiso explícito');
        self::assertSame('would_overwrite', json_decode($client->getResponse()->getContent(), true)['error']);
    }

    public function testOtherUsersCannotOpenTheBatch(): void
    {
        $client = static::createClient();
        [, $batchId] = $this->seedBatch('batch-owner@example.com');
        $intruder = $this->makeUser(static::getContainer()->get('doctrine')->getManager(), 'batch-intruder@example.com');
        static::getContainer()->get('doctrine')->getManager()->flush();

        $client->loginUser($intruder);
        $client->request('GET', '/solicitudes/nueva/realizar/lote/' . $batchId . '/plantilla');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{User, string, list<AccessRequest>} */
    private function seedBatch(string $email): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->makeUser($em, $email);

        $law = (new ApplicableLaw())->setName('Ley 19/2013 de transparencia')->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $batchId = Uuid::v7()->toRfc4122();
        $drafts = [];
        foreach (['Ministerio A', 'Ministerio B'] as $i => $name) {
            $body = (new PublicBody())->setName($name . ' ' . bin2hex(random_bytes(3)))->setTransparencyPortalAmbId(200000 + $i);
            $em->persist($body);
            $this->trackFixtureBody($body->getId()->toRfc4122());

            $ar = (new AccessRequest())
                ->setUser($user)
                ->setPublicBody($body)
                ->setApplicableLaw($law)
                ->setStatus(AccessRequest::STATUS_PENDING)
                ->setTitle('')
                ->setDescription('');
            $ar->setMetadataValue('draft_batch_id', $batchId);
            $em->persist($ar);
            $this->trackFixtureRequest($ar->getId()->toRfc4122());
            $drafts[] = $ar;
            usleep(1000); // distinct createdAt → stable order (the first draft hosts the template turns)
        }
        $em->flush();

        return [$user, $batchId, $drafts];
    }

    private function makeUser(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())
            ->setEmail(bin2hex(random_bytes(3)) . '-' . $email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }
}
