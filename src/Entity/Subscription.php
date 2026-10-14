<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Billing subscription (spec §3.2). Belongs to a user (Professional) or an
 * organization (Team/Organization), exactly one of the two.
 *
 * Stores no card data: payment always happens in Stripe (hosted Checkout +
 * Customer Portal). `stripeSubscriptionId` is unique so the webhook stays
 * idempotent at row level as well as per event.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_UNPAID = 'unpaid';

    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 32)]
    private string $plan;

    #[ORM\Column(length: 16)]
    private string $interval = self::INTERVAL_MONTH;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_INCOMPLETE;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pastDueSince = null;

    /**
     * When the owner was notified of entering read-only (daily
     * `app:billing:notify-readonly` command). Null = not yet notified.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readonlyNotifiedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
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

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getInterval(): string
    {
        return $this->interval;
    }

    public function setInterval(string $interval): static
    {
        $this->interval = $interval;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        return $this;
    }

    public function getPastDueSince(): ?\DateTimeImmutable
    {
        return $this->pastDueSince;
    }

    public function setPastDueSince(?\DateTimeImmutable $pastDueSince): static
    {
        $this->pastDueSince = $pastDueSince;
        return $this;
    }

    public function getReadonlyNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->readonlyNotifiedAt;
    }

    public function setReadonlyNotifiedAt(?\DateTimeImmutable $readonlyNotifiedAt): static
    {
        $this->readonlyNotifiedAt = $readonlyNotifiedAt;
        return $this;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): static
    {
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
