<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest\Batch;

use App\Entity\User;
use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\LegalArticleRepository;
use App\Repository\RegDestinationRepository;
use App\Service\AccessRequest\Batch\BatchVersionGenerator;
use App\Service\AccessRequest\Batch\DraftBatchOverview;
use App\Service\AccessRequest\Batch\DraftBatchTemplate;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\JudicialHistoryAnnotator;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ResolutionRetriever;
use App\Service\Legal\KeyArticleSelector;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\RequesterCapacityResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\HttpClient\MockHttpClient;

final class BatchVersionGeneratorTest extends TestCase
{
    use BatchFixtures;

    /** @var list<array<string, mixed>> turns written to the version's own thread */
    private array $threadWrites = [];

    private function template(): DraftBatchTemplate
    {
        return new DraftBatchTemplate(
            batchId: '0199a000-0000-7000-8000-000000000001',
            title: 'Contratos menores de [[ORGANISMO]]',
            expone: 'Me dirijo al [[ORGANISMO]] para conocer sus contratos menores.',
            solicita: 'Al amparo de la [[LEY_APLICABLE]], solicito la relación de contratos menores de 2025.',
            sources: [['type' => 'resolution', 'reference' => 'R/0001/2024', 'label' => 'R/0001/2024']],
        );
    }

    public function testModelAdaptationIsAppliedAndRecordedOnTheVersion(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects(self::once())->method('chatJson')
            ->with(self::callback(function (ChatRequest $req): bool {
                // The recipient's own prompt + the template with its values.
                self::assertStringContainsString('Ministerio de Hacienda', $req->systemPrompt);
                self::assertStringContainsString('[[ORGANISMO]] → Ministerio de Hacienda', (string) $req->userText);
                self::assertStringContainsString('[[LEY_APLICABLE]] → Ley 19/2013', (string) $req->userText);

                return true;
            }))
            ->willReturn(['action' => 'generate', 'draft' => [
                'title' => 'Contratos menores del Ministerio de Hacienda',
                'body_text' => 'Al amparo de la Ley 19/2013, solicito la relación de contratos menores de 2025.',
            ]]);

        $ar = $this->draft('Ministerio de Hacienda', 'Ley 19/2013');
        $result = $this->generator($llm)->generate($ar, $this->template(), new User());

        self::assertFalse($result['fallback']);
        self::assertSame('Contratos menores del Ministerio de Hacienda', $ar->getTitle());
        self::assertSame(DraftBatchOverview::STATE_TEMPLATE, DraftBatchOverview::classify($ar));
        self::assertSame($this->template()->fingerprint(), $ar->getMetadataValue(BatchVersionGenerator::METADATA_KEY)['template_fingerprint']);
        self::assertSame($this->template()->sources, $ar->getMetadataValue('cited_sources'));

        self::assertCount(1, $this->threadWrites, 'la versión estrena su propio hilo explicando de dónde sale');
        self::assertSame('assistant', $this->threadWrites[0]['role']);
        self::assertStringContainsString('plantilla común', $this->threadWrites[0]['content']);
    }

    public function testFallsBackToDeterministicFillWhenTheModelLeavesMarkers(): void
    {
        $llm = $this->createStub(LlmClient::class);
        $llm->method('chatJson')->willReturn(['action' => 'generate', 'draft' => [
            'title' => 'Contratos de [[ORGANISMO]]',
            'expone' => 'x',
            'solicita' => 'y',
        ]]);

        $ar = $this->draft('Xunta de Galicia', 'Lei 1/2016', 'Consellería de Facenda');
        $result = $this->generator($llm)->generate($ar, $this->template(), new User());

        self::assertTrue($result['fallback']);
        self::assertSame('Contratos menores de Xunta de Galicia', $ar->getTitle());
        self::assertSame('Me dirijo al Xunta de Galicia para conocer sus contratos menores.', $ar->getExpone());
        self::assertStringContainsString('Lei 1/2016', (string) $ar->getSolicita());
        self::assertStringNotContainsString('[[', (string) $ar->getDescription());
        self::assertStringContainsString('léela antes de enviarla', $this->threadWrites[0]['content']);
    }

    public function testFallbackOnPortalChannelMergesExponeAndSolicitaIntoOneBody(): void
    {
        $llm = $this->createStub(LlmClient::class);
        $llm->method('chatJson')->willThrowException(new \RuntimeException('timeout'));

        $ar = $this->draft('Ministerio de Hacienda', 'Ley 19/2013');
        $this->generator($llm)->generate($ar, $this->template(), new User());

        self::assertSame(
            "EXPONE:\nMe dirijo al Ministerio de Hacienda para conocer sus contratos menores.\n\nSOLICITA:\nAl amparo de la Ley 19/2013, solicito la relación de contratos menores de 2025.",
            $ar->getDescription(),
        );
    }

    public function testPortalVersionKeepsExponeSolicitaParagraphsAndLists(): void
    {
        $solicita = "Solicito el acceso a la siguiente información pública de la Casa Real:\n\n"
            . "Relación de obsequios recibidos por los altos cargos de la Casa Real.\n\n"
            . "1. Identificación del beneficiario.\n2. Fecha de recepción.\n3. Valor económico estimado.";

        $llm = $this->createMock(LlmClient::class);
        $llm->expects(self::once())->method('chatJson')
            ->with(self::callback(static function (ChatRequest $req): bool {
                // The model is asked for expone/solicita on EVERY channel, never to merge them.
                return str_contains((string) $req->userText, '`expone` y `solicita` por separado')
                    && !str_contains((string) $req->userText, 'texto corrido');
            }))
            ->willReturn(['action' => 'generate', 'draft' => [
                'title' => 'Obsequios recibidos por altos cargos de la Casa Real',
                'expone' => 'Me dirijo a la Casa Real al amparo de la Ley 19/2013.',
                'solicita' => $solicita,
            ]]);

        $ar = $this->draft('Casa Real', 'Ley 19/2013');
        $this->generator($llm)->generate($ar, $this->template(), new User());

        self::assertSame(
            "EXPONE:\nMe dirijo a la Casa Real al amparo de la Ley 19/2013.\n\nSOLICITA:\n" . $solicita,
            $ar->getDescription(),
            'el cuerpo único del portal conserva el formato EXPONE/SOLICITA, los párrafos y la lista',
        );
    }

    private function generator(LlmClient $llm): BatchVersionGenerator
    {
        $promptStore = new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 4)),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        );
        $resolutionRetriever = new ResolutionRetriever(
            $this->createStub(StoreInterface::class),
            $this->createStub(EmbeddingGenerator::class),
            $this->createStub(\App\Repository\ResolutionRepository::class),
            new JudicialHistoryAnnotator($this->createMock(\App\Repository\JudgmentRepository::class)),
        );
        $lawResolver = new ApplicableLawResolver(
            $this->createStub(ApplicableLawRepository::class),
            $this->createStub(RegDestinationRepository::class),
            $this->createStub(AutonomousCommunityRepository::class),
        );
        $legalArticles = $this->createStub(LegalArticleRepository::class);
        $composer = new RequestPromptComposer(
            $resolutionRetriever,
            $lawResolver,
            $promptStore,
            new LegalFrameworkComposer($legalArticles, new RequesterCapacityResolver(), new KeyArticleSelector($legalArticles)),
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            foreach (json_decode((string) ($params['turns'] ?? '[]'), true) as $turn) {
                $this->threadWrites[] = $turn;
            }

            return 1;
        });

        return new BatchVersionGenerator(
            $composer,
            new RequestDraftGenerator($composer, $this->createStub(EmbeddingGenerator::class), $resolutionRetriever, $llm),
            $llm,
            new ChatHistoryStore($connection),
            new NullLogger(),
        );
    }
}
