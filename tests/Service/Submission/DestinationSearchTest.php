<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\RegDestinationRetriever;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\DestinationCandidate;
use App\Service\Submission\DestinationSearch;
use App\Service\Submission\DestinationSearchFilters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

/**
 * Covers the pure, high-risk logic of the unified destination search: the
 * semantic-boost dedup predicate (accent-folded name + DIR3 code), the LIKE
 * metacharacter escaping, and the initiate-target shaping. The full UNION query
 * and the merge wiring are validated live against the database.
 */
final class DestinationSearchTest extends TestCase
{
    private function service(): DestinationSearch
    {
        // matchesKeyword/fold use no collaborators — skip the constructor.
        return (new ReflectionClass(DestinationSearch::class))->newInstanceWithoutConstructor();
    }

    private function destination(string $name, string $dir3): RegDestination
    {
        $body = (new PublicBody())->setName('Raíz');

        return new RegDestination($body, $dir3, $name);
    }

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    public function testMatchesKeywordFoldsAccentsAndIsCaseInsensitive(): void
    {
        $svc = $this->service();
        $dest = $this->destination('CONSELLERÍA DE MEDIO AMBIENTE', 'A12048934');

        // Query without accents / different case still matches the accented name.
        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $dest, 'conselleria'));
        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $dest, 'MEDIO ambiente'));
        // A term absent from the name does not match by name.
        $this->assertFalse($this->invoke($svc, 'matchesKeyword', $dest, 'urbanismo'));
    }

    public function testMatchesKeywordMatchesByDir3Code(): void
    {
        $svc = $this->service();
        $dest = $this->destination('CONSELLERÍA DE MEDIO AMBIENTE', 'A12048934');

        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $dest, 'A12048934'));
        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $dest, 'a12048'));
        $this->assertFalse($this->invoke($svc, 'matchesKeyword', $dest, 'Z99999999'));
    }

    /**
     * The boost prepends a semantic hit ONLY when it does not satisfy the keyword
     * predicate — this is what keeps it from re-appearing on a later keyword page.
     */
    public function testDedupPredicateGuardsAgainstCrossPageDuplicates(): void
    {
        $svc = $this->service();
        $literalHit = $this->destination('Servicio de Sanidad', 'S01');
        $pureSemantic = $this->destination('Consellería de Sanidade', 'S02');

        // "sanidad" appears in the first name → it would show in keyword results → skip.
        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $literalHit, 'sanidad'));
        // "servicio de salud" is nowhere in the second name/code → safe to prepend.
        $this->assertFalse($this->invoke($svc, 'matchesKeyword', $pureSemantic, 'servicio de salud'));
    }

    public function testMatchesKeywordSpansNameAndComunidadAcrossTokens(): void
    {
        $svc = $this->service();
        $body = (new PublicBody())->setName('Comunidad Autónoma de Galicia');
        $dest = (new RegDestination($body, 'A12048934', 'CONSELLERÍA DE MEDIO AMBIENTE'))
            ->setComunidad('Galicia');

        // "galicia" is in comunidad, "medio ambiente" in the name → all tokens
        // match across fields (the multi-word query the old whole-phrase LIKE missed).
        $this->assertTrue($this->invoke($svc, 'matchesKeyword', $dest, 'medio ambiente galicia'));
        // A token present nowhere fails the AND.
        $this->assertFalse($this->invoke($svc, 'matchesKeyword', $dest, 'medio ambiente cataluña'));
    }

    public function testEscapeLikeNeutralisesMetacharacters(): void
    {
        $m = new ReflectionMethod(RegDestinationRepository::class, 'escapeLike');
        $m->setAccessible(true);

        $this->assertSame('100\\_2', $m->invoke(null, '100_2'));
        $this->assertSame('50\\%', $m->invoke(null, '50%'));
        $this->assertSame('a\\\\b', $m->invoke(null, 'a\\b'));
        $this->assertSame('A12048934', $m->invoke(null, 'A12048934'));
    }

    /**
     * The merge must rank strong literal keyword hits (match_rank <= 2) ABOVE the
     * semantic suggestions, with weak facet-only keyword hits (match_rank 3) below —
     * so the exact match "AGENCIA TURISMO DE GALICIA" is no longer buried under
     * loosely-related semantic bodies.
     */
    public function testStrongKeywordHitsOutrankSemanticWhichOutrankWeakKeyword(): void
    {
        // A semantically-similar body that does NOT satisfy the keyword predicate
        // (its name lacks "turismo"), so the boost keeps it.
        $body = (new PublicBody())->setName('Consellería de Sanidade');
        $semanticDest = (new RegDestination($body, 'S99', 'Consellería de Sanidade'))
            ->setNivelAdministracion('Administración Autonómica');
        $semanticId = $semanticDest->getId()->toRfc4122();

        $regRepo = $this->createMock(RegDestinationRepository::class);
        $regRepo->method('searchUnifiedCandidates')->willReturn([
            $this->keywordRow('AGENCIA TURISMO DE GALICIA', matchRank: 1),
            $this->keywordRow('SERVICIO CON GALICIA EN LA COMUNIDAD', matchRank: 3),
        ]);
        $regRepo->method('findByIds')->willReturn([$semanticId => $semanticDest]);
        // Steer the (real) law resolver to the state-law branch, which we stub to null.
        $regRepo->method('bodyHasStateLevelDestination')->willReturn(true);

        // Real retriever wired to a stubbed vector store: the final class can't be
        // doubled, but its collaborators can, so we feed it one scored document.
        $embeddings = $this->createMock(EmbeddingGenerator::class);
        $embeddings->method('generate')->willReturn([0.1, 0.2, 0.3]);
        $store = $this->createMock(StoreInterface::class);
        $store->method('query')->willReturn([
            new VectorDocument($semanticId, new Vector([0.1, 0.2, 0.3]), new Metadata([
                'regDestinationId' => $semanticId,
                'text' => 'Consellería de Sanidade',
            ]), 0.41),
        ]);
        $retriever = new RegDestinationRetriever($store, $embeddings, $regRepo);

        $publicBodyRepo = $this->createMock(PublicBodyRepository::class);
        $publicBodyRepo->method('find')->willReturn(null); // → applicableLaw null for keyword rows

        // Real (final) resolver with stubbed repos: no community + no state law → null.
        $lawRepo = $this->createMock(ApplicableLawRepository::class);
        $lawRepo->method('findStateLaw')->willReturn(null);
        $lawResolver = new ApplicableLawResolver(
            $lawRepo,
            $regRepo,
            $this->createMock(AutonomousCommunityRepository::class),
        );

        $service = new DestinationSearch($regRepo, $publicBodyRepo, $lawResolver, $retriever);

        $result = $service->search(
            'agencia turismo galicia',
            new DestinationSearchFilters(nivel: null, comunidad: null, provincia: null),
            20,
            0,
        );

        $this->assertCount(3, $result->items);
        $this->assertSame('AGENCIA TURISMO DE GALICIA', $result->items[0]->name);
        $this->assertFalse($result->items[0]->semantic, 'the strong literal match must rank first');
        $this->assertTrue($result->items[1]->semantic, 'the semantic hit follows the strong match');
        $this->assertSame('SERVICIO CON GALICIA EN LA COMUNIDAD', $result->items[2]->name);
        $this->assertFalse($result->items[2]->semantic, 'the weak facet-only keyword hit sinks below the semantic hit');
    }

    /**
     * @return array<string, mixed> a raw searchUnifiedCandidates() row
     */
    private function keywordRow(string $name, int $matchRank): array
    {
        return [
            'kind' => 'reg',
            'public_body_id' => '019e2dae-c1c1-7a02-a5d0-c51e321db269',
            'reg_destination_id' => '019e2dae-c1ee-7c1f-8847-6c97925fd303',
            'name' => $name,
            'display_label' => $name,
            'dir3_code' => 'A12025020',
            'comunidad' => 'Galicia',
            'provincia' => 'Coruña, A',
            'nivel_administracion' => 'Administración Autonómica',
            'oficina_dir3' => null,
            'oficina_name' => null,
            'raiz_dir3' => 'A12002994',
            'raiz_name' => 'Comunidad Autónoma de Galicia',
            'match_rank' => $matchRank,
        ];
    }

    public function testToInitiateTargetRegVsPortal(): void
    {
        $reg = new DestinationCandidate(
            kind: DestinationCandidate::KIND_REG,
            publicBodyId: 'body-uuid',
            regDestinationId: 'reg-uuid',
            name: 'Unidad',
            displayLabel: 'Unidad',
            dir3Code: 'A1',
            comunidad: null, provincia: null,
            nivelAdministracion: 'Administración Autonómica', nivelLabel: 'Administración Autonómica',
            channel: 'submit_request_reg', channelLabel: 'REG',
            applicableLaw: null,
        );
        $portal = new DestinationCandidate(
            kind: DestinationCandidate::KIND_PORTAL,
            publicBodyId: 'body-uuid',
            regDestinationId: null,
            name: 'Ministerio',
            displayLabel: 'Ministerio',
            dir3Code: 'E1',
            comunidad: null, provincia: null,
            nivelAdministracion: 'Administración del Estado', nivelLabel: 'Administración del Estado',
            channel: 'submit_request_transparencia', channelLabel: 'Portal Transparencia',
            applicableLaw: null,
        );

        $this->assertSame(['publicBodyId' => 'body-uuid', 'regDestinationId' => 'reg-uuid'], $reg->toInitiateTarget());
        $this->assertSame(['publicBodyId' => 'body-uuid'], $portal->toInitiateTarget());
    }
}
