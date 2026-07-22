<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent\Tool;

use App\Entity\AccessRequest;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Agent\AgentRequestContext;
use App\Service\AI\Agent\Tool\EditRequestDraftTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * edit_request solo puede tocar la solicitud de la conversación (gate duro por
 * UUID contra AgentRequestContext), solo si es del usuario autenticado y solo
 * mientras sigue en borrador (STATUS_PENDING). La escritura pasa por
 * RequestDraftGenerator::applyDraft, la misma vía que el canvas de /redactar.
 *
 * Sin BD: repositorio y EntityManager mockeados; el RequestDraftGenerator es el
 * real del contenedor (applyDraft no llama al LLM).
 */
final class EditRequestDraftToolTest extends KernelTestCase
{
    private EntityManagerInterface&MockObject $em;

    public function testRejectsWhenConversationHasNoEditableRequest(): void
    {
        [$user, $request] = $this->draftRequest();
        $tool = $this->tool($user, $request, contextId: null);

        $result = $tool($request->getId()->toRfc4122(), title: 'Nuevo título');

        self::assertStringContainsString('no está disponible', $result);
        self::assertSame('Título original', $request->getTitle());
    }

    public function testRejectsUuidMismatchWithoutTouchingAnything(): void
    {
        [$user, $request] = $this->draftRequest();
        $tool = $this->tool($user, $request, contextId: Uuid::v7()->toRfc4122());

        $result = $tool($request->getId()->toRfc4122(), title: 'Nuevo título');

        self::assertStringContainsString('solicitud de esta conversación', $result);
        self::assertSame('Título original', $request->getTitle());
    }

    public function testRejectsWhenCallerIsNotTheOwner(): void
    {
        [, $request] = $this->draftRequest();
        $stranger = $this->user('stranger@example.com');
        $tool = $this->tool($stranger, $request, contextId: $request->getId()->toRfc4122());

        $result = $tool($request->getId()->toRfc4122(), title: 'Nuevo título');

        self::assertStringContainsString('No tienes acceso', $result);
        self::assertSame('Título original', $request->getTitle());
    }

    public function testRejectsWhenRequestIsAlreadySent(): void
    {
        [$user, $request] = $this->draftRequest();
        $request->setStatus(AccessRequest::STATUS_SENT);
        $tool = $this->tool($user, $request, contextId: $request->getId()->toRfc4122());

        $result = $tool($request->getId()->toRfc4122(), title: 'Nuevo título');

        self::assertStringContainsString('ya se ha enviado', $result);
        self::assertSame('Título original', $request->getTitle());
    }

    public function testRejectsWhenNoFieldProvided(): void
    {
        [$user, $request] = $this->draftRequest();
        $tool = $this->tool($user, $request, contextId: $request->getId()->toRfc4122());

        $result = $tool($request->getId()->toRfc4122());

        self::assertStringContainsString('ningún cambio', $result);
    }

    public function testEditsTitleAndBodyOnPortalChannelAndFlushes(): void
    {
        [$user, $request] = $this->draftRequest();
        $tool = $this->tool($user, $request, contextId: $request->getId()->toRfc4122(), expectFlush: true);

        $result = $tool(
            $request->getId()->toRfc4122(),
            title: 'Título editado',
            body: '<p>Cuerpo nuevo.</p><p>Segundo párrafo.</p>',
        );

        self::assertSame('Título editado', $request->getTitle());
        self::assertStringContainsString('Cuerpo nuevo.', $request->getDescription());
        // applyDraft normaliza el HTML conservando la separación de párrafos.
        self::assertStringContainsString("\n", $request->getDescription());
        self::assertStringContainsString('actualizado', $result);
    }

    public function testPartialEditKeepsUntouchedFields(): void
    {
        [$user, $request] = $this->draftRequest();
        $tool = $this->tool($user, $request, contextId: $request->getId()->toRfc4122(), expectFlush: true);

        $tool($request->getId()->toRfc4122(), title: 'Solo el título');

        self::assertSame('Solo el título', $request->getTitle());
        self::assertSame('Descripción original.', $request->getDescription());
    }

    public function testRegChannelEditsExponeAndKeepsSolicitaInSync(): void
    {
        [$user, $request] = $this->draftRequest();
        $request->setRegDestination(new RegDestination($request->getPublicBody(), 'O00000001', 'Registro test'));
        $request->setExpone('Expone original.');
        $request->setSolicita('Solicita original.');
        $tool = $this->tool($user, $request, contextId: $request->getId()->toRfc4122(), expectFlush: true);

        $tool($request->getId()->toRfc4122(), expone: 'Expone editado.');

        self::assertSame('Expone editado.', $request->getExpone());
        // El campo no indicado se conserva, y description se mantiene sincronizada.
        self::assertSame('Solicita original.', $request->getSolicita());
        self::assertStringContainsString('Expone editado.', $request->getDescription());
        self::assertStringContainsString('Solicita original.', $request->getDescription());
    }

    /** @return array{0: User, 1: AccessRequest} */
    private function draftRequest(): array
    {
        $user = $this->user('owner@example.com');

        $body = new PublicBody();
        $body->setName('Ayto Edit');

        $request = new AccessRequest();
        $request->setUser($user);
        $request->setPublicBody($body);
        $request->setTitle('Título original');
        $request->setDescription('Descripción original.');
        $request->setStatus(AccessRequest::STATUS_PENDING);

        return [$user, $request];
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('x');
        $user->setFirstName('Edit');
        $user->setLastName('Tool');

        return $user;
    }

    private function tool(?User $user, AccessRequest $request, ?string $contextId, bool $expectFlush = false): EditRequestDraftTool
    {
        self::bootKernel();

        $repository = $this->createMock(AccessRequestRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (Uuid $id) => $id->toRfc4122() === $request->getId()->toRfc4122() ? $request : null,
        );

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($expectFlush ? self::once() : self::never())->method('flush');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $context = new AgentRequestContext();
        if ($contextId !== null) {
            $context->setEditableRequestId($contextId);
        }

        return new EditRequestDraftTool(
            $repository,
            static::getContainer()->get(RequestDraftGenerator::class),
            $context,
            $security,
            new AgentProgress(),
            $this->em,
        );
    }
}
