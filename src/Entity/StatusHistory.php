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
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_COURT = 'courtStatus';
    public const TYPE_RESOLUTION = 'resolution';

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
            self::TYPE_RESOLUTION => 'Resolución',
            default => $this->statusType,
        };
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getFromStatusLabel(): string
    {
        return $this->translateStatus($this->statusType, $this->fromStatus);
    }

    public function getToStatusLabel(): string
    {
        return $this->translateStatus($this->statusType, $this->toStatus);
    }

    private function translateStatus(string $statusType, string $status): string
    {
        return match ($statusType) {
            self::TYPE_STATUS => match ($status) {
                'sent' => 'Enviada',
                'processing' => 'En trámite',
                'granted' => 'Concedida',
                'granted_completed' => 'Completada',
                'partially_granted' => 'Estimación parcial',
                'denied' => 'Denegada',
                'inadmitted' => 'Inadmitida',
                'delayed' => 'Silencio',
                'pending' => 'Pendiente',
                default => $status,
            },
            self::TYPE_COMPLAINT => match ($status) {
                'none' => 'Sin reclamación',
                'reclaimed' => 'Reclamada',
                'complaint_granted' => 'Estimada',
                'complaint_denied' => 'Desestimada',
                'complaint_archived' => 'Archivada',
                default => $status,
            },
            self::TYPE_COURT => match ($status) {
                'none' => 'Sin recurso',
                'in_court' => 'En tribunal',
                'court_granted' => 'Favorable',
                'court_denied' => 'Desfavorable',
                default => $status,
            },
            self::TYPE_RESOLUTION => match ($status) {
                'none' => 'Sin resolución',
                'granted' => 'Concesión total',
                'partially_granted' => 'Concesión parcial',
                'denied' => 'Denegación',
                'inadmitted' => 'Inadmisión',
                'silence' => 'Silencio administrativo',
                default => $status,
            },
            default => $status,
        };
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

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
