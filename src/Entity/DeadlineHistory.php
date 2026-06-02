<?php

namespace App\Entity;

use App\Repository\DeadlineHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeadlineHistoryRepository::class)]
#[ORM\Index(columns: ['access_request_id', 'created_at'], name: 'idx_deadline_request_created')]
class DeadlineHistory
{
    public const TYPE_RESPONSE = 'response';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_COMPLIANCE = 'compliance';
    public const TYPE_THIRD_PARTY_ALLEGATIONS = 'third_party_allegations';
    public const TYPE_HEARING = 'hearing';

    public const REASON_INITIAL = 'initial';
    public const REASON_EXTENSION = 'extension';
    public const REASON_COMPLAINT_RESOLUTION = 'complaint_resolution';
    public const REASON_THIRD_PARTY_SUSPENSION = 'third_party_suspension';
    public const REASON_THIRD_PARTY_RESUMED = 'third_party_resumed';
    public const REASON_PROCESSING_START = 'processing_start';
    public const REASON_LAW_CHANGE = 'law_change';
    public const REASON_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class, inversedBy: 'deadlineHistory')]
    #[ORM\JoinColumn(nullable: false)]
    private AccessRequest $accessRequest;

    #[ORM\Column(length: 50)]
    private string $deadlineType;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $previousDeadline = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $newDeadline;

    #[ORM\Column(length: 50)]
    private string $reason;

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

    public function getDeadlineType(): string
    {
        return $this->deadlineType;
    }

    public function setDeadlineType(string $deadlineType): static
    {
        $this->deadlineType = $deadlineType;
        return $this;
    }

    public function getDeadlineTypeLabel(): string
    {
        return match ($this->deadlineType) {
            self::TYPE_RESPONSE => 'Plazo de respuesta',
            self::TYPE_COMPLAINT => 'Plazo de reclamación',
            self::TYPE_COMPLIANCE => 'Plazo de cumplimiento',
            self::TYPE_THIRD_PARTY_ALLEGATIONS => 'Plazo alegaciones terceros',
            self::TYPE_HEARING => 'Plazo de alegaciones (audiencia)',
            default => $this->deadlineType,
        };
    }

    public function getPreviousDeadline(): ?\DateTimeImmutable
    {
        return $this->previousDeadline;
    }

    public function setPreviousDeadline(?\DateTimeImmutable $previousDeadline): static
    {
        $this->previousDeadline = $previousDeadline;
        return $this;
    }

    public function getNewDeadline(): \DateTimeImmutable
    {
        return $this->newDeadline;
    }

    public function setNewDeadline(\DateTimeImmutable $newDeadline): static
    {
        $this->newDeadline = $newDeadline;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_INITIAL => 'Plazo inicial',
            self::REASON_EXTENSION => 'Prórroga',
            self::REASON_COMPLAINT_RESOLUTION => 'Resolución de reclamación',
            self::REASON_THIRD_PARTY_SUSPENSION => 'Suspensión por alegaciones de terceros',
            self::REASON_THIRD_PARTY_RESUMED => 'Reanudación tras alegaciones de terceros',
            self::REASON_PROCESSING_START => 'Inicio de tramitación',
            self::REASON_LAW_CHANGE => 'Cambio de ley aplicable',
            self::REASON_MANUAL => 'Ajuste manual',
            default => $this->reason,
        };
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
