<?php

namespace App\Entity;

use App\Repository\AccessRequestComplaintRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessRequestComplaintRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AccessRequestComplaint
{
    public const STATUS_RECLAIMED = 'reclaimed';
    public const STATUS_GRANTED = 'complaint_granted';
    public const STATUS_DENIED = 'complaint_denied';
    public const STATUS_ARCHIVED = 'complaint_archived';

    public const STATUSES = [
        self::STATUS_RECLAIMED,
        self::STATUS_GRANTED,
        self::STATUS_DENIED,
        self::STATUS_ARCHIVED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(inversedBy: 'complaint', targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequest $accessRequest;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_RECLAIMED;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $complianceDeadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $filedAt = null;

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

    public function getAccessRequest(): AccessRequest
    {
        return $this->accessRequest;
    }

    public function setAccessRequest(AccessRequest $accessRequest): static
    {
        $this->accessRequest = $accessRequest;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
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

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RECLAIMED => 'Reclamada',
            self::STATUS_GRANTED => 'Reclamación estimada',
            self::STATUS_DENIED => 'Reclamación desestimada',
            self::STATUS_ARCHIVED => 'Reclamación archivada',
            default => $this->status,
        };
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(?\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;
        return $this;
    }

    public function getComplianceDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->complianceDeadlineAt;
    }

    public function setComplianceDeadlineAt(?\DateTimeImmutable $complianceDeadlineAt): static
    {
        $this->complianceDeadlineAt = $complianceDeadlineAt;
        return $this;
    }

    public function getFiledAt(): ?\DateTimeImmutable
    {
        return $this->filedAt;
    }

    public function setFiledAt(?\DateTimeImmutable $filedAt): static
    {
        $this->filedAt = $filedAt;
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

    public function isDeadlinePassed(): bool
    {
        if ($this->deadlineAt === null) {
            return false;
        }
        return $this->deadlineAt < new \DateTimeImmutable('today');
    }

    public function getDaysUntilDeadline(): int
    {
        if ($this->deadlineAt === null) {
            return 0;
        }
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->deadlineAt);
        return $interval->invert ? -$interval->days : $interval->days;
    }

    public function isComplianceDeadlinePassed(): bool
    {
        if ($this->complianceDeadlineAt === null) {
            return false;
        }
        return $this->complianceDeadlineAt < new \DateTimeImmutable('today');
    }

    public function getDaysUntilComplianceDeadline(): int
    {
        if ($this->complianceDeadlineAt === null) {
            return 0;
        }
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->complianceDeadlineAt);
        return $interval->invert ? -$interval->days : $interval->days;
    }
}
