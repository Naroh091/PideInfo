<?php

namespace App\Entity;

use App\Repository\AccessRequestComplaintRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    // Complaint result — what the transparency council actually decided.
    // Orthogonal to $status (workflow). NULL until resolved.
    public const RESULT_UPHELD = 'upheld';
    public const RESULT_PARTIALLY_UPHELD = 'partially_upheld';
    public const RESULT_DISMISSED = 'dismissed';
    public const RESULT_INADMITTED = 'inadmitted';
    public const RESULT_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(inversedBy: 'complaint', targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequest $accessRequest;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    /**
     * Historical log of every externalId this complaint has ever had. The
     * "current" externalId above is canonical; this list is the union and is
     * used by lookups so a late upload referring to an obsolete ref still
     * resolves to this complaint.
     *
     * @var list<string>
     */
    #[ORM\Column(
        type: Types::JSON,
        options: ['default' => '[]', 'jsonb' => true],
        columnDefinition: "JSONB DEFAULT '[]'::jsonb NOT NULL",
    )]
    private array $externalIds = [];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $expedienteEstado = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $expedienteTitulo = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fechaApertura = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fechaCierre = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_RECLAIMED;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $complaintResult = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $complianceDeadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $filedAt = null;

    /** @var Collection<int, HearingProcess> */
    #[ORM\OneToMany(mappedBy: 'complaint', targetEntity: HearingProcess::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['endDate' => 'DESC'])]
    private Collection $hearingProcesses;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->hearingProcesses = new ArrayCollection();
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
        if ($externalId !== null && !in_array($externalId, $this->externalIds, true)) {
            $this->externalIds[] = $externalId;
        }
        return $this;
    }

    /** @return list<string> */
    public function getExternalIds(): array
    {
        return $this->externalIds;
    }

    public function hasExternalId(string $externalId): bool
    {
        return $this->externalId === $externalId
            || in_array($externalId, $this->externalIds, true);
    }

    public function getExpedienteEstado(): ?string
    {
        return $this->expedienteEstado;
    }

    public function setExpedienteEstado(?string $expedienteEstado): static
    {
        $this->expedienteEstado = $expedienteEstado;
        return $this;
    }

    public function getExpedienteTitulo(): ?string
    {
        return $this->expedienteTitulo;
    }

    public function setExpedienteTitulo(?string $expedienteTitulo): static
    {
        $this->expedienteTitulo = $expedienteTitulo;
        return $this;
    }

    public function getFechaApertura(): ?\DateTimeImmutable
    {
        return $this->fechaApertura;
    }

    public function setFechaApertura(?\DateTimeImmutable $fechaApertura): static
    {
        $this->fechaApertura = $fechaApertura;
        return $this;
    }

    public function getFechaCierre(): ?\DateTimeImmutable
    {
        return $this->fechaCierre;
    }

    public function setFechaCierre(?\DateTimeImmutable $fechaCierre): static
    {
        $this->fechaCierre = $fechaCierre;
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

    public function getComplaintResult(): ?string
    {
        return $this->complaintResult;
    }

    public function setComplaintResult(?string $complaintResult): static
    {
        $this->complaintResult = $complaintResult;
        return $this;
    }

    public function getComplaintResultLabel(): ?string
    {
        return match ($this->complaintResult) {
            self::RESULT_UPHELD => 'Estimada',
            self::RESULT_PARTIALLY_UPHELD => 'Estimada parcialmente',
            self::RESULT_DISMISSED => 'Desestimada',
            self::RESULT_INADMITTED => 'Inadmitida',
            self::RESULT_ARCHIVED => 'Archivada',
            default => null,
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

    /** @return Collection<int, HearingProcess> */
    public function getHearingProcesses(): Collection
    {
        return $this->hearingProcesses;
    }

    public function addHearingProcess(HearingProcess $hearingProcess): static
    {
        if (!$this->hearingProcesses->contains($hearingProcess)) {
            $this->hearingProcesses->add($hearingProcess);
            $hearingProcess->setComplaint($this);
        }
        return $this;
    }

    /**
     * Trámite de audiencia vivo: el de fecha límite más lejana entre los no
     * vencidos. Null cuando no hay ninguno abierto.
     */
    public function getActiveHearingProcess(): ?HearingProcess
    {
        $active = null;
        foreach ($this->hearingProcesses as $hearing) {
            if (!$hearing->isActive()) {
                continue;
            }
            if ($active === null || $hearing->getEndDate() > $active->getEndDate()) {
                $active = $hearing;
            }
        }
        return $active;
    }

    /**
     * El trámite más relevante para mostrar: el activo o, si todos vencieron,
     * el de fecha límite más reciente.
     */
    public function getLatestHearingProcess(): ?HearingProcess
    {
        $active = $this->getActiveHearingProcess();
        if ($active !== null) {
            return $active;
        }

        $latest = null;
        foreach ($this->hearingProcesses as $hearing) {
            if ($latest === null || $hearing->getEndDate() > $latest->getEndDate()) {
                $latest = $hearing;
            }
        }
        return $latest;
    }

    public function __toString(): string
    {
        return $this->externalId ?? 'Reclamación ' . substr((string) $this->id, 0, 8);
    }

    /** Idempotencia: trámite ya registrado para este documento, si existe. */
    public function findHearingProcessByTriggerDocument(Document $document): ?HearingProcess
    {
        foreach ($this->hearingProcesses as $hearing) {
            $trigger = $hearing->getTriggerDocument();
            if ($trigger !== null && $trigger->getId()->equals($document->getId())) {
                return $hearing;
            }
        }
        return null;
    }
}
