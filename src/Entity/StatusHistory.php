<?php

namespace App\Entity;

use App\Repository\StatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StatusHistoryRepository::class)]
#[ORM\Index(columns: ['access_request_id', 'created_at'], name: 'idx_request_created')]
class StatusHistory
{
    public const TYPE_STATUS = 'status';
    public const TYPE_COMPLAINT = 'complaintStatus';
    public const TYPE_COURT = 'courtStatus';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class, inversedBy: 'statusHistory')]
    #[ORM\JoinColumn(nullable: false)]
    private AccessRequest $accessRequest;

    #[ORM\Column(length: 50)]
    private string $statusType;

    #[ORM\Column(length: 50)]
    private string $fromStatus;

    #[ORM\Column(length: 50)]
    private string $toStatus;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Document $triggerDocument = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getStatusType(): string
    {
        return $this->statusType;
    }

    public function setStatusType(string $statusType): static
    {
        $this->statusType = $statusType;
        return $this;
    }

    public function getStatusTypeLabel(): string
    {
        return match ($this->statusType) {
            self::TYPE_STATUS => 'Estado principal',
            self::TYPE_COMPLAINT => 'Reclamación',
            self::TYPE_COURT => 'Vía judicial',
            default => $this->statusType,
        };
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function setFromStatus(string $fromStatus): static
    {
        $this->fromStatus = $fromStatus;
        return $this;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function setToStatus(string $toStatus): static
    {
        $this->toStatus = $toStatus;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getTriggerDocument(): ?Document
    {
        return $this->triggerDocument;
    }

    public function setTriggerDocument(?Document $triggerDocument): static
    {
        $this->triggerDocument = $triggerDocument;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
