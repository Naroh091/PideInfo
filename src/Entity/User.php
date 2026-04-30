<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Ya existe una cuenta con este correo electrónico')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /** @var array<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 255)]
    private string $firstName;

    #[ORM\Column(length: 255)]
    private string $lastName;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $virtualEmail = null;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Organization $organization = null;

    /** @var Collection<int, AccessRequest> */
    #[ORM\OneToMany(targetEntity: AccessRequest::class, mappedBy: 'user')]
    private Collection $accessRequests;

    /**
     * Pending DEHú notifications keyed by notificationId.
     * Not tied to a specific AccessRequest because DEHú sent_references don't map
     * to transparency expediente refs — shown as a global user-level list instead.
     * @var array<string, array>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pendingDehuNotifications = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $agentTokenIssuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $agentTokensInvalidatedAt = null;

    /**
     * AI-generated 1-2 paragraph summary of the user's activity over the last 24 h.
     * Persisted so the home dashboard renders instantly from cache; regenerated
     * asynchronously by `App\MessageHandler\WarmActivitySummaryHandler` when the
     * fingerprint of the recent notifications changes.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $activitySummaryHtml = null;

    /** sha1 of the ordered notification UUIDs the cached summary was built from. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $activitySummaryFingerprint = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activitySummaryUpdatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->accessRequests = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return array<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param array<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear any temporary sensitive data
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getVirtualEmail(): ?string
    {
        return $this->virtualEmail;
    }

    public function setVirtualEmail(?string $virtualEmail): static
    {
        $this->virtualEmail = $virtualEmail;
        return $this;
    }

    public function hasVirtualEmail(): bool
    {
        return $this->virtualEmail !== null;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    /** @return Collection<int, AccessRequest> */
    public function getAccessRequests(): Collection
    {
        return $this->accessRequests;
    }

    public function addAccessRequest(AccessRequest $accessRequest): static
    {
        if (!$this->accessRequests->contains($accessRequest)) {
            $this->accessRequests->add($accessRequest);
            $accessRequest->setUser($this);
        }
        return $this;
    }

    public function removeAccessRequest(AccessRequest $accessRequest): static
    {
        $this->accessRequests->removeElement($accessRequest);
        return $this;
    }

    public function getPendingDehuNotifications(): array
    {
        return $this->pendingDehuNotifications ?? [];
    }

    public function setPendingDehuNotifications(?array $notifications): static
    {
        $this->pendingDehuNotifications = $notifications;
        return $this;
    }

    public function getAgentTokenIssuedAt(): ?\DateTimeImmutable
    {
        return $this->agentTokenIssuedAt;
    }

    public function setAgentTokenIssuedAt(?\DateTimeImmutable $agentTokenIssuedAt): static
    {
        $this->agentTokenIssuedAt = $agentTokenIssuedAt;
        return $this;
    }

    public function getAgentTokensInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->agentTokensInvalidatedAt;
    }

    public function setAgentTokensInvalidatedAt(?\DateTimeImmutable $agentTokensInvalidatedAt): static
    {
        $this->agentTokensInvalidatedAt = $agentTokensInvalidatedAt;
        return $this;
    }

    public function isAgentConnected(): bool
    {
        return null !== $this->agentTokenIssuedAt
            && (null === $this->agentTokensInvalidatedAt || $this->agentTokensInvalidatedAt < $this->agentTokenIssuedAt);
    }

    public function getActivitySummaryHtml(): ?string
    {
        return $this->activitySummaryHtml;
    }

    public function setActivitySummaryHtml(?string $activitySummaryHtml): static
    {
        $this->activitySummaryHtml = $activitySummaryHtml;
        return $this;
    }

    public function getActivitySummaryFingerprint(): ?string
    {
        return $this->activitySummaryFingerprint;
    }

    public function setActivitySummaryFingerprint(?string $activitySummaryFingerprint): static
    {
        $this->activitySummaryFingerprint = $activitySummaryFingerprint;
        return $this;
    }

    public function getActivitySummaryUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->activitySummaryUpdatedAt;
    }

    public function setActivitySummaryUpdatedAt(?\DateTimeImmutable $activitySummaryUpdatedAt): static
    {
        $this->activitySummaryUpdatedAt = $activitySummaryUpdatedAt;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
