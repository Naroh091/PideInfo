<?php

declare(strict_types=1);

namespace App\Tests\Service\Anonymous;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Service\Anonymous\AnonymousDraftClaimer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Claim de borradores anónimos: asigna dueño, repara la incoherencia
 * «pending + resolutionResult» de las reclamaciones mapeando el resultado a
 * su estado real, deja rastro en StatusHistory y es idempotente.
 */
final class AnonymousDraftClaimerTest extends KernelTestCase
{
    public function testComplaintClaimAssignsOwnerAndRepairsStatus(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        // Simulated anonymous browser session holding the draft id.
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        /** @var RequestStack $stack */
        $stack = $container->get('request_stack');
        $stack->push($request);

        $body = new PublicBody();
        $body->setName('Organismo claim de prueba');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Reclamación anónima');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        $draft->setMetadataValue('anonymous', ['flow' => 'complaint', 'turns' => 3]);
        $draft->setMetadataValue('complaint_chat_history_complaint', [
            ['role' => 'user', 'kind' => 'text', 'content' => 'Me denegaron', 'ts' => '2026-07-14T00:00:00+00:00'],
        ]);
        $em->persist($draft);

        $user = new User();
        $user->setEmail('claimer-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('Claim');
        $em->persist($user);
        $em->flush();

        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);

        try {
            /** @var AnonymousDraftClaimer $claimer */
            $claimer = $container->get(AnonymousDraftClaimer::class);

            self::assertSame(1, $claimer->claim($user));

            $em->refresh($draft);
            self::assertSame($user->getId()->toRfc4122(), $draft->getUser()?->getId()->toRfc4122());
            self::assertSame(AccessRequest::STATUS_FINISHED, $draft->getStatus(), 'resolutionResult denied → posición finished');
            self::assertNull($draft->getMetadataValue('anonymous'), 'la marca anónima desaparece');
            self::assertIsArray($draft->getMetadataValue('complaint_chat_history_complaint'), 'el historial de chat sobrevive para el fallback');
            self::assertSame([], $session->get('anon_draft_ids', []), 'la sesión queda limpia');

            $history = $em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $draft]);
            self::assertNotEmpty($history, 'el claim deja rastro en StatusHistory');

            // Idempotencia: un segundo claim (p. ej. register + login) no hace nada.
            $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
            self::assertSame(0, $claimer->claim($user));
        } finally {
            $stack->pop();
            foreach ($em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $draft]) as $h) {
                $em->remove($h);
            }
            $em->remove($draft);
            $em->remove($user);
            $em->remove($body);
            $em->remove($law);
            $em->flush();
        }
    }

    public function testComplaintClaimMaterializesDraftDocument(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);

        $body = new PublicBody();
        $body->setName('Organismo materialize de prueba');
        $em->persist($body);
        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Reclamación con texto');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        $draft->setMetadataValue('anonymous', ['flow' => 'complaint', 'turns' => 3]);
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Texto de la reclamación generada</p>');
        $draft->setMetadataValue('complaint_chat_history_complaint', [
            ['role' => 'user', 'kind' => 'text', 'content' => 'Me denegaron', 'ts' => '2026-07-14T00:00:00+00:00'],
        ]);
        $em->persist($draft);

        $user = new User();
        $user->setEmail('materialize-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('Materialize');
        $em->persist($user);
        $em->flush();

        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);

        try {
            $container->get(\App\Service\Anonymous\AnonymousDraftClaimer::class)->claim($user);

            $em->refresh($draft);
            $document = $draft->getComplaintDraftDocument();
            self::assertNotNull($document, 'el claim materializa el borrador como Document');
            self::assertSame($user->getId()->toRfc4122(), $document->getUploadedBy()?->getId()->toRfc4122());
            self::assertNotEmpty($document->getAiMetadata()['chat_history'] ?? [], 'el historial anónimo migra al documento');
            self::assertNull($draft->getMetadataValue('anonymous_complaint_html'), 'la clave se limpia tras materializar');
            self::assertIsArray($draft->getMetadataValue('complaint_chat_history_complaint'), 'se mantiene como fallback legacy de ChatHistoryStore::load() para sembrar el historial LLM autenticado en el primer turno');
        } finally {
            $stack = $container->get('request_stack');
            $stack->pop();
            foreach ($em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $draft]) as $h) {
                $em->remove($h);
            }
            $document = $draft->getComplaintDraftDocument();
            if ($document !== null) {
                $em->remove($document);
            }
            $em->remove($draft);
            $em->remove($user);
            $em->remove($body);
            $em->remove($law);
            $em->flush();
        }
    }

    public function testComplaintClaimWithoutHtmlCreatesNoDocument(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);

        $body = new PublicBody();
        $body->setName('Organismo materialize de prueba sin html');
        $em->persist($body);
        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Reclamación sin texto');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        $draft->setMetadataValue('anonymous', ['flow' => 'complaint', 'turns' => 3]);
        $draft->setMetadataValue('complaint_chat_history_complaint', [
            ['role' => 'user', 'kind' => 'text', 'content' => 'Me denegaron', 'ts' => '2026-07-14T00:00:00+00:00'],
        ]);
        $em->persist($draft);

        $user = new User();
        $user->setEmail('materialize-nohtml-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('SinHtml');
        $em->persist($user);
        $em->flush();

        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);

        try {
            $container->get(\App\Service\Anonymous\AnonymousDraftClaimer::class)->claim($user);

            $em->refresh($draft);
            self::assertNull($draft->getComplaintDraftDocument(), 'sin HTML no hay nada que materializar');
            self::assertSame(AccessRequest::STATUS_FINISHED, $draft->getStatus(), 'la reparación de estado sigue funcionando sin materializar');
        } finally {
            $stack = $container->get('request_stack');
            $stack->pop();
            foreach ($em->getRepository(StatusHistory::class)->findBy(['accessRequest' => $draft]) as $h) {
                $em->remove($h);
            }
            $em->remove($draft);
            $em->remove($user);
            $em->remove($body);
            $em->remove($law);
            $em->flush();
        }
    }
}
