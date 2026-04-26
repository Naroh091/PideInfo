<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Security;

use App\Security\OAuth2\OAuthTokenContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class OAuthTokenContextTest extends TestCase
{
    public function testHasScopeReturnsTrueForGrantedScope(): void
    {
        $ctx = new OAuthTokenContext();
        $ctx->set('00000000-0000-0000-0000-000000000001', 'client-1', 'tok-1', ['mcp:read', 'mcp:write']);

        self::assertTrue($ctx->hasScope('mcp:read'));
        self::assertTrue($ctx->hasScope('mcp:write'));
        self::assertFalse($ctx->hasScope('mcp:documents'));
    }

    public function testRequireScopeThrowsWhenMissing(): void
    {
        $ctx = new OAuthTokenContext();
        $ctx->set('00000000-0000-0000-0000-000000000001', 'client-1', 'tok-1', ['mcp:read']);

        $this->expectException(AccessDeniedException::class);
        $ctx->requireScope('mcp:write');
    }

    public function testRequireScopeIsSilentWhenGranted(): void
    {
        $ctx = new OAuthTokenContext();
        $ctx->set('00000000-0000-0000-0000-000000000001', 'client-1', 'tok-1', ['mcp:read']);

        $ctx->requireScope('mcp:read');
        self::assertSame('client-1', $ctx->getClientId());
        self::assertSame(['mcp:read'], $ctx->getScopes());
    }

    public function testGettersThrowWhenContextEmpty(): void
    {
        $ctx = new OAuthTokenContext();

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\UnexpectedValueException::class);
        $ctx->getUserId();
    }
}
