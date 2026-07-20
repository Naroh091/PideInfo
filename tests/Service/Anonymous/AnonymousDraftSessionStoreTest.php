<?php

declare(strict_types=1);

namespace App\Tests\Service\Anonymous;

use App\Service\Anonymous\AnonymousDraftSessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Submit intent for anonymous drafts: stores intent when visitor reaches send page
 * and consumes it once on post-claim login to land on the claimed request/complaint.
 */
final class AnonymousDraftSessionStoreTest extends TestCase
{
    private Session $session;
    private AnonymousDraftSessionStore $store;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->store = new AnonymousDraftSessionStore($stack);
    }

    public function testRememberAndConsumeSubmitIntent(): void
    {
        $id = Uuid::v7();
        $this->store->rememberSubmitIntent($id, 'complaint');

        self::assertSame(
            ['id' => $id->toRfc4122(), 'flow' => 'complaint'],
            $this->store->consumeSubmitIntent(),
        );
        self::assertNull($this->store->consumeSubmitIntent(), 'la intención se consume una sola vez');
    }

    public function testRememberOverwritesPreviousIntent(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->store->rememberSubmitIntent($first, 'request');
        $this->store->rememberSubmitIntent($second, 'request');

        self::assertSame($second->toRfc4122(), $this->store->consumeSubmitIntent()['id']);
    }

    public function testMalformedIntentIsDiscarded(): void
    {
        $this->session->set('anon_submit_intent', 'garbage');

        self::assertNull($this->store->consumeSubmitIntent());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la clave corrupta se limpia');
    }

    public function testNoSessionMeansNoIntent(): void
    {
        $store = new AnonymousDraftSessionStore(new RequestStack());

        self::assertNull($store->consumeSubmitIntent());
    }
}
