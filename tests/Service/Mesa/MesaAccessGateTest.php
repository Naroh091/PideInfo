<?php

declare(strict_types=1);

namespace App\Tests\Service\Mesa;

use App\Service\Mesa\MesaAccessGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class MesaAccessGateTest extends TestCase
{
    private function gate(string $passwords): MesaAccessGate
    {
        $stack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);

        return new MesaAccessGate($stack, $passwords);
    }

    public function testCorrectPasswordGrantsAccess(): void
    {
        $gate = $this->gate('CTBG');

        self::assertFalse($gate->isGranted());
        self::assertTrue($gate->attempt('CTBG'));
        self::assertTrue($gate->isGranted());
    }

    public function testAnyOfTheCommaSeparatedPasswordsWorks(): void
    {
        $gate = $this->gate('CTBG, segunda-clave ,tercera');

        self::assertTrue($gate->attempt('segunda-clave'));
        self::assertTrue($gate->isGranted());
    }

    public function testWrongPasswordIsRejected(): void
    {
        $gate = $this->gate('CTBG,otra');

        self::assertFalse($gate->attempt('ctbg'));
        self::assertFalse($gate->attempt('CTBG,otra'));
        self::assertFalse($gate->isGranted());
    }

    public function testSurroundingWhitespaceInTheAttemptIsIgnored(): void
    {
        $gate = $this->gate('CTBG');

        self::assertTrue($gate->attempt('  CTBG  '));
    }

    public function testEmptyConfigurationFailsClosed(): void
    {
        $gate = $this->gate('');

        self::assertFalse($gate->attempt(''));
        self::assertFalse($gate->attempt('CTBG'));
        self::assertFalse($gate->isGranted());
        self::assertSame([], $gate->allowedPasswords());
    }

    public function testStrayCommasProduceNoEmptyPasswords(): void
    {
        $gate = $this->gate(',CTBG,, ,');

        self::assertSame(['CTBG'], $gate->allowedPasswords());
        self::assertFalse($gate->attempt(''));
    }

    public function testRevokeRemovesAccess(): void
    {
        $gate = $this->gate('CTBG');
        $gate->attempt('CTBG');
        $gate->revoke();

        self::assertFalse($gate->isGranted());
    }

    public function testWithoutSessionNothingIsGranted(): void
    {
        $gate = new MesaAccessGate(new RequestStack(), 'CTBG');

        self::assertFalse($gate->isGranted());
    }
}
