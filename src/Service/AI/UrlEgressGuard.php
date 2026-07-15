<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Pre-flight SSRF guard for agent tools that fetch an LLM-supplied URL
 * (`visit_url`, `scrape_url`). Rejects non-http(s) schemes and any host that
 * resolves to a private, loopback, link-local or otherwise reserved address —
 * most importantly the cloud metadata endpoint `169.254.169.254`.
 *
 * IMPORTANT — this is a PRE-FLIGHT check only. The actual TCP/DNS egress happens
 * in the remote fetchers (CamoFox / Crawl4AI), which re-resolve the host, so this
 * guard does NOT close DNS-rebinding. The strong control is a network egress
 * policy on those services; this guard is defense-in-depth (and, for anonymous
 * users, moot because the egress tools are withheld entirely — see
 * AgentChatOrchestrator::EGRESS_TOOLS).
 */
class UrlEgressGuard
{
    /** IPv4/IPv6 CIDR blocks rejected on top of PHP's private/reserved filter flags. */
    private const EXTRA_DENY_CIDRS = [
        '169.254.0.0/16',  // link-local incl. cloud metadata 169.254.169.254
        '100.64.0.0/10',   // CGNAT / shared address space (RFC 6598)
        '::ffff:0:0/96',   // IPv4-mapped IPv6 (avoid bypass via ::ffff:127.0.0.1)
        'fc00::/7',        // unique local IPv6
        'fe80::/10',       // link-local IPv6
    ];

    /**
     * @throws \InvalidArgumentException when the URL is malformed, not http(s),
     *                                   or resolves to a non-public address.
     */
    public function assertPublic(string $url): void
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL no válida.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Solo se permiten URLs http(s).');
        }

        $host = $parts['host'];
        // parse_url keeps IPv6 literals in brackets, e.g. "[::1]".
        $host = trim($host, '[]');

        $ips = $this->resolveIps($host);
        if ($ips === []) {
            throw new \InvalidArgumentException('No se pudo resolver el host de la URL.');
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \InvalidArgumentException('La URL apunta a una dirección de red interna o reservada.');
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_DENY_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves a host to its IPv4 + IPv6 addresses. An IP literal is returned
     * as-is (no lookup). Overridable in tests to avoid real DNS.
     *
     * @return string[]
     */
    protected function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
