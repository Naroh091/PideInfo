<?php

declare(strict_types=1);

namespace App\Tests\Service\Mesa;

use App\Service\Mesa\MesaPinStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class MesaPinStoreTest extends TestCase
{
    private function store(): MesaPinStore
    {
        $stack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);

        return new MesaPinStore($stack);
    }

    public function testPinKeepsInsertionOrder(): void
    {
        $store = $this->store();
        $store->pin('b');
        $store->pin('a');

        self::assertSame(['b', 'a'], $store->ids());
        self::assertTrue($store->has('a'));
    }

    public function testPinningTwiceDoesNotDuplicate(): void
    {
        $store = $this->store();
        $store->pin('a');
        $store->pin('a');

        self::assertSame(1, $store->count());
    }

    public function testNotesOnlyAttachToPinnedResolutions(): void
    {
        $store = $this->store();
        $store->pin('a');
        $store->setNote('a', '  citar FJ 5  ');
        $store->setNote('fantasma', 'nada');

        self::assertSame('citar FJ 5', $store->all()['a']['note']);
        self::assertFalse($store->has('fantasma'));
    }

    public function testUnpinAndClear(): void
    {
        $store = $this->store();
        $store->pin('a');
        $store->pin('b');
        $store->unpin('a');

        self::assertSame(['b'], $store->ids());

        $store->clear();
        self::assertSame(0, $store->count());
    }

    public function testCapAtTwentyPins(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 25; $i++) {
            $store->pin('id-' . $i);
        }

        self::assertSame(20, $store->count());
    }
}
