<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Prompt\BundledPromptLoader;
use App\Prompt\CompiledPrompt;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\RegDestinationRepository;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\ResolutionRetriever;
use App\Service\Submission\ApplicableLawResolver;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\HttpClient\MockHttpClient;

final class RequestPromptComposerTest extends TestCase
{
    private function composer(): RequestPromptComposer
    {
        $promptStore = new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 5)), // tests/Service/AI/Chat/Composer → raíz
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        );

        // ResolutionRetriever is final — instantiate with stubbed dependencies.
        // formatForPrompt() is NOT called when we pass [] as similarResolutions.
        $registry = $this->createStub(ManagerRegistry::class);
        $resolutionRetriever = new ResolutionRetriever(
            $this->createStub(StoreInterface::class),
            $this->createStub(EmbeddingGenerator::class),
            $this->createStub(\App\Repository\ResolutionRepository::class),
        );

        // ApplicableLawResolver is final — instantiate with stubbed repositories.
        // deadlineLabel() only calls methods on the ApplicableLaw entity (no DB calls).
        $lawResolver = new ApplicableLawResolver(
            $this->createStub(ApplicableLawRepository::class),
            $this->createStub(RegDestinationRepository::class),
            $this->createStub(AutonomousCommunityRepository::class),
        );

        return new RequestPromptComposer($resolutionRetriever, $lawResolver, $promptStore);
    }

    private function accessRequest(): AccessRequest
    {
        $law = $this->createStub(ApplicableLaw::class);
        $law->method('getName')->willReturn('Ley 19/2013');
        $law->method('getShortCode')->willReturn('LTAIPBG');
        $law->method('getResponseDeadlineValue')->willReturn(1);
        $law->method('getResponseDeadlineUnit')->willReturn('months');
        $law->method('isSilenceIsPositive')->willReturn(false);

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Madrid');

        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        $ar->setUser(new User());
        $ar->setApplicableLaw($law);
        $ar->setTitle('Datos de contratos 2024');
        $ar->setDescription('');
        // Sin regDestination → canal Portal/correo. Sin descripción → no hay borrador.

        return $ar;
    }

    public function testComposeReturnsCompiledPromptLinkedToLangfuseName(): void
    {
        $compiled = $this->composer()->compose($this->accessRequest(), []);

        $this->assertInstanceOf(CompiledPrompt::class, $compiled);
        $this->assertSame('pideinfo-request-generate-request-chat', $compiled->name);
    }

    public function testComposeContainsInlinePolicyAndDomainTemplate(): void
    {
        $compiled = $this->composer()->compose($this->accessRequest(), []);

        // Protocolo inline (se queda en PHP).
        $this->assertStringContainsString('Política de decisión', $compiled->text);
        $this->assertStringContainsString('Formato de salida (OBLIGATORIO)', $compiled->text);
        // Contenido de dominio (desde el template bundled).
        $this->assertStringContainsString('Cómo redactar una buena solicitud', $compiled->text);
        $this->assertStringContainsString('Cita siempre la ley aplicable', $compiled->text);
        // Contexto sustituido.
        $this->assertStringContainsString('Ayuntamiento de Madrid', $compiled->text);
        // Canal Portal/correo seleccionado (no REG).
        $this->assertStringContainsString('Portal de Transparencia', $compiled->text);
        $this->assertStringNotContainsString('{{', $compiled->text);
    }
}
