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

    /**
     * Personal data the REG (Registro Electrónico Común) forces users to fill on
     * every submission. We store it here once so the agent can fan-out without
     * a UI round-trip per request. All nullable so existing accounts can complete
     * the profile lazily the first time they send to a REG-bound body — see
     * `hasCompleteRegProfile()` for the gating rule.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $addressStreetType = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $addressLine = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $addressCountry = null;

    /** Provincia tal y como aparece en el desplegable del REG (sin código INE). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addressProvince = null;

    /** Municipio tal y como aparece en el desplegable del REG (filtrado por provincia). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $addressMunicipality = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $addressPostalCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contactPhone = null;

    /**
     * Ids (string UUID) of the UsageHint announcements this user has dismissed
     * from the dashboard "Novedades" block, so they are not shown again.
     * @var array<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dismissedHints = null;

    /**
     * Per-user AI writing style preferences injected into the drafting assistant
     * system prompt. All keys are optional; absent keys are silently skipped.
     * `learned` holds durable style preferences the assistant inferred from the
     * user's own correction instructions (e.g. "Al usuario le gusta ir al grano").
     * @var array{tone?: string, salutation?: string, closing?: string, notes?: string, learned?: list<string>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $writingPreferences = null;

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

    public function getAddressStreetType(): ?string { return $this->addressStreetType; }
    public function setAddressStreetType(?string $value): static { $this->addressStreetType = $value; return $this; }

    public function getAddressLine(): ?string { return $this->addressLine; }
    public function setAddressLine(?string $value): static { $this->addressLine = $value; return $this; }

    public function getAddressCountry(): ?string { return $this->addressCountry; }
    public function setAddressCountry(?string $value): static { $this->addressCountry = $value; return $this; }

    public function getAddressProvince(): ?string { return $this->addressProvince; }
    public function setAddressProvince(?string $value): static { $this->addressProvince = $value; return $this; }

    public function getAddressMunicipality(): ?string { return $this->addressMunicipality; }
    public function setAddressMunicipality(?string $value): static { $this->addressMunicipality = $value; return $this; }

    public function getAddressPostalCode(): ?string { return $this->addressPostalCode; }
    public function setAddressPostalCode(?string $value): static { $this->addressPostalCode = $value; return $this; }

    public function getContactPhone(): ?string { return $this->contactPhone; }
    public function setContactPhone(?string $value): static { $this->contactPhone = $value; return $this; }

    /**
     * True when the User has every field the REG step-1 form requires. The
     * country code is treated as defaulting to "ES" — if you've ever filled
     * the address, you've implicitly picked Spain unless you set it otherwise.
     */
    public function hasCompleteRegProfile(): bool
    {
        return $this->addressStreetType !== null && $this->addressStreetType !== ''
            && $this->addressLine !== null && $this->addressLine !== ''
            && $this->addressProvince !== null && $this->addressProvince !== ''
            && $this->addressMunicipality !== null && $this->addressMunicipality !== ''
            && $this->addressPostalCode !== null && $this->addressPostalCode !== ''
            && $this->contactPhone !== null && $this->contactPhone !== '';
    }

    /** @return array<string> string UUIDs of dismissed UsageHints */
    public function getDismissedHints(): array
    {
        return $this->dismissedHints ?? [];
    }

    public function dismissHint(string $hintId): static
    {
        $dismissed = $this->getDismissedHints();
        if (!\in_array($hintId, $dismissed, true)) {
            $dismissed[] = $hintId;
            $this->dismissedHints = $dismissed;
        }
        return $this;
    }

    public function hasDismissedHint(string $hintId): bool
    {
        return \in_array($hintId, $this->getDismissedHints(), true);
    }

    /** @return array{tone?: string, salutation?: string, closing?: string, notes?: string, learned?: list<string>} */
    public function getWritingPreferences(): array
    {
        return $this->writingPreferences ?? [];
    }

    /** @param array{tone?: string, salutation?: string, closing?: string, notes?: string, learned?: list<string>} $prefs */
    public function setWritingPreferences(array $prefs): static
    {
        $this->writingPreferences = $prefs !== [] ? $prefs : null;
        return $this;
    }

    public function hasWritingPreferences(): bool
    {
        return !empty($this->writingPreferences);
    }

    /** Upper bound on stored learned preferences, to keep the injected prompt bounded. */
    private const MAX_LEARNED_PREFERENCES = 15;

    /** @return list<string> */
    public function getLearnedPreferences(): array
    {
        $learned = $this->writingPreferences['learned'] ?? [];

        return is_array($learned)
            ? array_values(array_filter($learned, static fn ($p): bool => is_string($p) && trim($p) !== ''))
            : [];
    }

    /**
     * Reconcile the learned writing-style preferences with the deltas the assistant
     * emitted: drop entries the user contradicted (`remove`), append new ones (`add`).
     * Matching for removal is case-insensitive (exact, then substring either way) so
     * the model can name a stale preference loosely. Returns whether anything changed.
     *
     * @param list<string> $add
     * @param list<string> $remove
     */
    public function applyLearnedPreferenceDeltas(array $add, array $remove): bool
    {
        $current = $this->getLearnedPreferences();
        $before = $current;

        $removeNorm = array_values(array_filter(
            array_map(static fn ($r): string => mb_strtolower(trim((string) $r)), $remove),
            static fn (string $r): bool => $r !== '',
        ));
        if ($removeNorm !== []) {
            $current = array_values(array_filter($current, static function (string $pref) use ($removeNorm): bool {
                $p = mb_strtolower(trim($pref));
                foreach ($removeNorm as $r) {
                    if ($p === $r || str_contains($p, $r) || str_contains($r, $p)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        foreach ($add as $candidate) {
            $pref = trim((string) $candidate);
            if ($pref === '') {
                continue;
            }
            $norm = mb_strtolower($pref);
            $dup = false;
            foreach ($current as $existing) {
                if (mb_strtolower(trim($existing)) === $norm) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $current[] = $pref;
            }
        }

        if (count($current) > self::MAX_LEARNED_PREFERENCES) {
            $current = array_slice($current, -self::MAX_LEARNED_PREFERENCES);
        }

        if ($current === $before) {
            return false;
        }

        $prefs = $this->getWritingPreferences();
        if ($current === []) {
            unset($prefs['learned']);
        } else {
            $prefs['learned'] = array_values($current);
        }
        $this->setWritingPreferences($prefs);

        return true;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
