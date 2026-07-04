<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Repository\RegDestinationRepository;
use App\Service\Submission\DestinationCandidate;
use App\Service\Submission\DestinationSearch;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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
