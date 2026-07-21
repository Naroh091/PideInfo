<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Endpoint app_solicitudes_change_status como lo usan los desplegables inline
 * del detalle: la vía nueva statusType=resolution y el resultado fino de
 * reclamación (complaintResult) via el mismo POST.
 */
final class AccessRequestChangeStatusControllerTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testResolutionDropdownSetsResolutionResult(): void
    {
        $client = static::createClient();
        [$request, $owner] = $this->seedRequest();
        $client->loginUser($owner);

        $token = $this->changeStatusToken($client, $request->getId()->toRfc4122());

        $client->request('POST', '/solicitudes/'.$request->getId()->toRfc4122().'/estado', [
            '_token' => $token,
            'statusType' => 'resolution',
            'newStatus' => AccessRequest::RESULT_PARTIALLY_GRANTED,
        ]);

        self::assertResponseRedirects();
        $fresh = $this->reload($request->getId()->toRfc4122());
        self::assertSame(AccessRequest::RESULT_PARTIALLY_GRANTED, $fresh->getResolutionResult());
        // El status NO se toca desde la vía de resolución.
        self::assertSame(AccessRequest::STATUS_DELAYED, $fresh->getStatus());
    }

    public function testComplaintDropdownAppliesFinerResult(): void
    {
        $client = static::createClient();
        [$request, $owner] = $this->seedRequest(withComplaint: true);
        $client->loginUser($owner);

        $token = $this->changeStatusToken($client, $request->getId()->toRfc4122());

        $client->request('POST', '/solicitudes/'.$request->getId()->toRfc4122().'/estado', [
            '_token' => $token,
            'statusType' => 'complaint',
            'newStatus' => AccessRequestComplaint::STATUS_GRANTED,
            'complaintResult' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
        ]);

        self::assertResponseRedirects();
        $fresh = $this->reload($request->getId()->toRfc4122());
        self::assertSame(AccessRequestComplaint::STATUS_GRANTED, $fresh->getComplaint()->getStatus());
        self::assertSame(AccessRequestComplaint::RESULT_PARTIALLY_UPHELD, $fresh->getComplaint()->getComplaintResult());
    }

    public function testInvalidResolutionValueViaXhrIsRejected(): void
    {
        $client = static::createClient();
        [$request, $owner] = $this->seedRequest();
        $client->loginUser($owner);

        $token = $this->changeStatusToken($client, $request->getId()->toRfc4122());

        $client->xmlHttpRequest('POST', '/solicitudes/'.$request->getId()->toRfc4122().'/estado', [
            '_token' => $token,
            'statusType' => 'resolution',
            'newStatus' => 'not_a_real_result',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testNonOwnerCannotSeeDetail(): void
    {
        $client = static::createClient();
        [$request] = $this->seedRequest();
        $stranger = $this->makeUser('stranger');
        $client->loginUser($stranger);

        $client->request('GET', '/solicitudes/'.$request->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    private function changeStatusToken(KernelBrowser $client, string $id): string
    {
        $crawler = $client->request('GET', '/solicitudes/'.$id);
        self::assertResponseIsSuccessful();

        return $crawler->filter('form.badge-edit-form input[name="_token"]')->first()->attr('value');
    }

    private function reload(string $id): AccessRequest
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(AccessRequest::class)->find($id);
    }

    /**
     * @return array{0: AccessRequest, 1: User}
     */
    private function seedRequest(bool $withComplaint = false): array
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $owner = $this->makeUser('owner');

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Prueba');
        $em->persist($body);
        $this->trackFixtureBody($body->getId()->toRfc4122());

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $request = new AccessRequest();
        $request->setUser($owner);
        $request->setPublicBody($body);
        $request->setApplicableLaw($law);
        $request->setTitle('Solicitud de prueba dropdown');
        $request->setDescription('Fixture');
        $request->setSentAt(new \DateTimeImmutable('-2 months'));
        $request->setDeadlineAt(new \DateTimeImmutable('-10 days'));
        $request->setStatus(AccessRequest::STATUS_DELAYED);
        $request->setResolutionResult(AccessRequest::RESULT_SILENCE);

        if ($withComplaint) {
            $complaint = new AccessRequestComplaint();
            $complaint->setAccessRequest($request);
            $complaint->setStatus(AccessRequestComplaint::STATUS_RECLAIMED);
            $request->setComplaint($complaint);
            $em->persist($complaint);
        }

        $em->persist($request);
        $em->flush();
        $this->trackFixtureRequest($request->getId()->toRfc4122());

        return [$request, $owner];
    }

    private function makeUser(string $tag): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = new User();
        $user->setEmail($tag.'+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $em->persist($user);
        $em->flush();
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }
}
