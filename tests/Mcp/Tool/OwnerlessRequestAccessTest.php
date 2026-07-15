<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Mcp\Tool\GetStatusHistoryTool;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Un cliente MCP autenticado que pasa el UUID de un borrador anónimo
 * (AccessRequest sin dueño, flujo /redactar) debe recibir una denegación
 * limpia, no un error 500 por deferenciar getUser() null. Falla cerrado.
 */
final class OwnerlessRequestAccessTest extends KernelTestCase
{
    public function testOwnerlessRequestIsDeniedNotCrashed(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo MCP anónimo');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        $ar->setApplicableLaw($law);
        $ar->setTitle('Borrador anónimo');
        $ar->setDescription('Fixture');
        $ar->setSentAt(new \DateTimeImmutable('today'));
        $ar->setDeadlineAt(new \DateTimeImmutable('+1 month'));
        $em->persist($ar);

        $caller = new User();
        $caller->setEmail('mcp-anon-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $caller->setPassword('x');
        $caller->setFirstName('Mcp');
        $caller->setLastName('Caller');
        $em->persist($caller);
        $em->flush();

        try {
            $security = $this->createMock(Security::class);
            $security->method('getUser')->willReturn($caller);

            $tokenContext = new OAuthTokenContext();
            $tokenContext->set($caller->getId()->toRfc4122(), 'test-client', 'token-id', ['mcp:read']);

            $tool = new GetStatusHistoryTool(
                $security,
                $tokenContext,
                static::getContainer()->get(AccessRequestRepository::class),
            );

            $this->expectException(AccessDeniedException::class);
            $tool($ar->getId()->toRfc4122());
        } finally {
            $em->remove($ar);
            $em->remove($caller);
            $em->remove($body);
            $em->remove($law);
            $em->flush();
        }
    }
}
