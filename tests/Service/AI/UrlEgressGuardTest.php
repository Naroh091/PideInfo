<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\UrlEgressGuard;
use PHPUnit\Framework\TestCase;

class UrlEgressGuardTest extends TestCase
{
    /**
     * Test double: resolves any host to a caller-supplied set of IPs so we never
     * touch real DNS. IP literals still short-circuit via the parent.
     */
    private function guardResolvingTo(array $ips): UrlEgressGuard
    {
        return new class($ips) extends UrlEgressGuard {
            public function __construct(private array $stubIps)
            {
            }

            protected function resolveIps(string $host): array
            {
                if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return [$host];
                }

                return $this->stubIps;
            }
        };
    }

    public function testAcceptsPublicIpLiteral(): void
    {
        $this->expectNotToPerformAssertions();
        (new UrlEgressGuard())->assertPublic('https://8.8.8.8/path');
    }

    public function testAcceptsPublicHostname(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guardResolvingTo(['93.184.216.34'])->assertPublic('https://example.com');
    }

    /**
     * @dataProvider privateAndReservedUrls
     */
    public function testRejectsNonPublicIpLiteral(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlEgressGuard())->assertPublic($url);
    }

    public static function privateAndReservedUrls(): array
    {
        return [
            'loopback v4'     => ['http://127.0.0.1/'],
            'private 10'      => ['http://10.1.2.3/'],
            'private 192.168' => ['http://192.168.0.1/'],
            'private 172.16'  => ['http://172.16.5.5/'],
            'metadata'        => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat'           => ['http://100.64.0.1/'],
            'loopback v6'     => ['http://[::1]/'],
            'link-local v6'   => ['http://[fe80::1]/'],
            'ipv4-mapped v6'  => ['http://[::ffff:127.0.0.1]/'],
        ];
    }

    public function testRejectsHostnameResolvingToPrivateIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Classic DNS-rebinding-style hostname pointing at the metadata endpoint.
        $this->guardResolvingTo(['169.254.169.254'])->assertPublic('https://evil.example.com');
    }

    public function testRejectsWhenAnyResolvedIpIsPrivate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // One public, one private → must reject (the fetcher might pick either).
        $this->guardResolvingTo(['93.184.216.34', '10.0.0.1'])->assertPublic('https://mixed.example.com');
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlEgressGuard())->assertPublic('file:///etc/passwd');
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlEgressGuard())->assertPublic('not a url');
    }

    public function testRejectsUnresolvableHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guardResolvingTo([])->assertPublic('https://nonexistent.invalid');
    }
}
