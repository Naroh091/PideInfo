<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DynamicClientMetadataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Side-table holding RFC 7591 metadata for clients registered via the public
 * Dynamic Client Registration endpoint. We avoid extending the bundle's
 * Client entity so the bundle's schema and managers remain untouched.
 */
#[ORM\Entity(repositoryClass: DynamicClientMetadataRepository::class)]
#[ORM\Table(name: 'oauth2_dynamic_client_metadata')]
class DynamicClientMetadata
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $clientIdentifier;

    #[ORM\Column(length: 255)]
    private string $clientName;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $logoUri = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $clientUri = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenEndpointAuthMethod = null;

    #[ORM\Column]
    private bool $dynamic = true;

    /**
     * SHA-256 hash of the registration access token (RFC 7592). Hashed so a
     * leaked DB doesn't expose the bearer token used to manage the client.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $registrationAccessTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $clientIdentifier, string $clientName)
    {
        $this->clientIdentifier = $clientIdentifier;
        $this->clientName = $clientName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function setClientName(string $clientName): self
    {
        $this->clientName = $clientName;

        return $this;
    }

    public function getLogoUri(): ?string
    {
        return $this->logoUri;
    }

    public function setLogoUri(?string $logoUri): self
    {
        $this->logoUri = $logoUri;

        return $this;
    }

    public function getClientUri(): ?string
    {
        return $this->clientUri;
    }

    public function setClientUri(?string $clientUri): self
    {
        $this->clientUri = $clientUri;

        return $this;
    }

    public function getTokenEndpointAuthMethod(): ?string
    {
        return $this->tokenEndpointAuthMethod;
    }

    public function setTokenEndpointAuthMethod(?string $method): self
    {
        $this->tokenEndpointAuthMethod = $method;

        return $this;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    public function setDynamic(bool $dynamic): self
    {
        $this->dynamic = $dynamic;

        return $this;
    }

    public function getRegistrationAccessTokenHash(): ?string
    {
        return $this->registrationAccessTokenHash;
    }

    public function setRegistrationAccessTokenHash(?string $hash): self
    {
        $this->registrationAccessTokenHash = $hash;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
