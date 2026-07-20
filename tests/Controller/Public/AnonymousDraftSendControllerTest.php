<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\ComplaintOrganism;
use App\Entity\PublicBody;
use App\Service\Anonymous\GenericDestination;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Página de envío del flujo público (/redactar/.../enviar): dos opciones
 * (registro con seguimiento automático / envío manual) con la vía recomendada
 * calculada por destinatario. Solo visible desde la sesión dueña del borrador.
 */
final class AnonymousDraftSendControllerTest extends WebTestCase
{
    private function makeDraft(
        KernelBrowser $client,
        ?int $ambId,
        string $flow = 'request',
        bool $generic = false,
        ?ComplaintOrganism $organism = null,
    ): AccessRequest {
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo envío de prueba ' . bin2hex(random_bytes(3)));
        if ($ambId !== null) {
            $body->setTransparencyPortalAmbId($ambId);
        }
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        if ($organism !== null) {
            $em->persist($organism);
            $law->setComplaintOrganism($organism);
        }
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Contratos menores 2025');
        $draft->setDescription('Solicito la relación de contratos menores.');
        $draft->setSentAt(new \DateTimeImmutable('today'));
        $draft->setDeadlineAt(new \DateTimeImmutable('+1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        if ($flow === 'complaint') {
            $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        }
        $draft->setMetadataValue('anonymous', ['flow' => $flow, 'turns' => 1]);
        if ($generic) {
            $draft->setMetadataValue(GenericDestination::METADATA_FLAG, true);
        }
        $em->persist($draft);
        $em->flush();

        return $draft;
    }

    /** Simulated anonymous browser session that owns the draft. */
    private function ownSession(KernelBrowser $client, AccessRequest $draft): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    public function testPortalChannelRecommendsWizardLink(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('idProc=133628&amp;idAmb=101509', $html);
        self::assertStringContainsString('Portal de la Transparencia', $html);
        self::assertStringContainsString('sede electrónica', $html, 'aclaración de que la sede también vale');
        self::assertStringContainsString('/redactar/solicitud/' . $draft->getId() . '/descargar-pdf', $html);

        $intent = $client->getRequest()->getSession()->get('anon_submit_intent');
        self::assertSame(['id' => $draft->getId()->toRfc4122(), 'flow' => 'request'], $intent);
    }

    public function testRegChannelRecommendsRedSara(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('rec.redsara.es', $html);
        self::assertStringContainsString('Registro Electrónico General', $html);
    }

    public function testGenericDestinationExplainsAllThreeChannels(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, generic: true);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('rec.redsara.es', $html);
        self::assertStringContainsString('A/A', $html, 'recordatorio del destinatario en blanco del PDF');
    }

    public function testForeignSessionIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        // no ownSession: the visitor's session does not contain the draft

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testNonPendingDraftIs404(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setStatus(AccessRequest::STATUS_DENIED);
        $em->flush();
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseStatusCodeSame(404);
    }

    public function testComplaintRecommendsCouncilForm(): void
    {
        $client = static::createClient();
        $organism = new ComplaintOrganism();
        $organism->setName('Consejo de prueba ' . bin2hex(random_bytes(3)));
        $organism->setComplaintFormUrl('https://consejo.example/reclamaciones');
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: $organism);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('https://consejo.example/reclamaciones', $html);
        self::assertStringContainsString($organism->getName(), $html);

        $intent = $client->getRequest()->getSession()->get('anon_submit_intent');
        self::assertSame(['id' => $draft->getId()->toRfc4122(), 'flow' => 'complaint'], $intent);
    }

    public function testComplaintWithoutCouncilFormDegradesToReg(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('rec.redsara.es', $client->getResponse()->getContent());
    }

    public function testComplaintAutosavePersistsHtml(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>Reclamación editada</p>']),
        );

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->refresh($draft);
        self::assertSame('<p>Reclamación editada</p>', $draft->getMetadataValue('anonymous_complaint_html'));
    }

    public function testComplaintAutosaveRejectsEmptyHtml(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>   </p>']),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testComplaintAutosaveDeniedForForeignSession(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        // sin ownSession

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>x</p>']),
        );

        self::assertResponseRedirects();
    }

    public function testComplaintPdfGetRendersFromMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Contenido persistido</p>');
        $em->flush();
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testComplaintPdfGetIs404WithoutMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testComplaintSendPageOffersPdfOnlyWithMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringNotContainsString('/descargar-pdf', $html, 'sin metadata no hay botón de descarga');
        self::assertStringContainsString('Vuelve al chat', $html);

        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Contenido</p>');
        $em->flush();

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf', $html);
    }
}
