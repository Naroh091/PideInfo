<?php

declare(strict_types=1);

namespace App\Service\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side verification of Cloudflare Turnstile tokens, guarding the
 * anonymous draft creation endpoint (POST /redactar/crear).
 *
 * With an empty secret (dev/test, or Turnstile not yet configured) every
 * token passes: the per-IP rate limiters remain as the only line of defence.
 */
final class TurnstileVerifier
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')]
        private readonly string $secretKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    public function verify(?string $token, ?string $clientIp = null): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::SITEVERIFY_URL, [
                'body' => array_filter([
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $clientIp,
                ]),
                'timeout' => 5,
            ]);

            $payload = $response->toArray(false);

            if (($payload['success'] ?? false) !== true) {
                $this->logger->info('turnstile.rejected', [
                    'errors' => $payload['error-codes'] ?? [],
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Fail open on Cloudflare outages: the rate limiters still cap
            // abuse, and a hard fail here would take the public flow down
            // with the third party.
            $this->logger->warning('turnstile.verify_failed', ['error' => $e->getMessage()]);

            return true;
        }
    }
}
