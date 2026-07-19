<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Eval\EvalCase;
use App\Eval\RetrievalDatasetStore;
use PHPUnit\Framework\TestCase;

final class RetrievalDatasetStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/retrieval-eval-test-' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $path = $this->dir . '/config/eval/retrieval/resolutions.yaml';
        if (is_file($path)) {
            unlink($path);
        }
        foreach (['/config/eval/retrieval', '/config/eval', '/config', ''] as $sub) {
            if (is_dir($this->dir . $sub)) {
                rmdir($this->dir . $sub);
            }
        }
    }

    public function testSaveLoadRoundtrip(): void
    {
        $store = new RetrievalDatasetStore($this->dir);
        $case = new EvalCase(
            id: EvalCase::makeId('manual', '¿retribuciones de altos cargos?'),
            query: '¿retribuciones de altos cargos?',
            relevant: ['0198c1c2-0000-7000-8000-000000000001'],
            source: 'manual',
            outcomes: ['favorable', 'partial'],
            meta: ['reference' => 'R-1-2026'],
        );

        $store->save([$case->id => $case]);
        $loaded = $store->load();

        self::assertCount(1, $loaded);
        self::assertArrayHasKey($case->id, $loaded);
        self::assertSame($case->query, $loaded[$case->id]->query);
        self::assertSame($case->relevant, $loaded[$case->id]->relevant);
        self::assertSame($case->outcomes, $loaded[$case->id]->outcomes);
        self::assertSame($case->meta, $loaded[$case->id]->meta);
    }

    public function testLoadMissingFileReturnsEmpty(): void
    {
        self::assertSame([], (new RetrievalDatasetStore($this->dir))->load());
    }

    public function testMergeKeepsExistingOnCollision(): void
    {
        $store = new RetrievalDatasetStore($this->dir);
        $curated = new EvalCase('id-1', 'query editada a mano', ['uuid-curated'], 'relations', EvalCase::ALL_OUTCOMES);
        $regenerated = new EvalCase('id-1', 'query regenerada', ['uuid-other'], 'relations', EvalCase::ALL_OUTCOMES);
        $fresh = new EvalCase('id-2', 'query nueva', ['uuid-2'], 'synthetic', ['favorable', 'partial']);

        $merged = $store->merge(['id-1' => $curated], ['id-1' => $regenerated, 'id-2' => $fresh]);

        self::assertCount(2, $merged);
        self::assertSame('query editada a mano', $merged['id-1']->query, 'hand-curated case must survive a rebuild');
        self::assertSame($fresh, $merged['id-2']);
    }

    public function testMakeIdIsStableAndNormalized(): void
    {
        self::assertSame(
            EvalCase::makeId('relations', '  Contratos Menores '),
            EvalCase::makeId('relations', 'contratos menores'),
        );
        self::assertStringStartsWith('relations-', EvalCase::makeId('relations', 'x'));
    }
}
