<?php

namespace App\Entity;

use App\Repository\AccessRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessRequestRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['status'], name: 'idx_status')]
#[ORM\Index(columns: ['deadline_at'], name: 'idx_deadline')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user')]
class AccessRequest
{
    // Primary status constants
    public const STATUS_SENT = 'sent';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_GRANTED = 'granted';
    public const STATUS_DENIED = 'denied';
    public const STATUS_DELAYED = 'delayed';
    public const STATUS_PENDING = 'pending';

    // Complaint status constants
    public const COMPLAINT_NONE = 'none';
    public const COMPLAINT_RECLAIMED = 'reclaimed';
    public const COMPLAINT_GRANTED = 'reclaim_granted';
    public const COMPLAINT_DENIED = 'reclaim_denied';

    // Court status constants
    public const COURT_NONE = 'none';
    public const COURT_IN_COURT = 'in_court';
    public const COURT_GRANTED = 'court_granted';
    public const COURT_DENIED = 'court_denied';

    // Third party allegations status constants
    public const THIRD_PARTY_NONE = 'none';
    public const THIRD_PARTY_PENDING = 'pending';
    public const THIRD_PARTY_RECEIVED = 'received';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_SENT;

    #[ORM\Column(length: 50)]
    private string $complaintStatus = self::COMPLAINT_NONE;

    #[ORM\Column(length: 50)]
    private string $courtStatus = self::COURT_NONE;

    #[ORM\Column(length: 50)]
    private string $thirdPartyStatus = self::THIRD_PARTY_NONE;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $thirdPartyAllegationsStartedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $thirdPartyAllegationsDeadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadlineSuspendedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $suspendedDaysRemaining = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'accessRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'accessRequests')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: PublicBody::class)]
    #[ORM\JoinColumn(nullable: false)]
    private PublicBody $publicBody;

    #[ORM\ManyToOne(targetEntity: PublicBody::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PublicBody $originalPublicBody = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $redirectedAt = null;

    #[ORM\ManyToOne(targetEntity: ApplicableLaw::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ApplicableLaw $applicableLaw;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processingStartedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $deadlineAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $originalDeadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $complaintDeadlineAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $complianceDeadlineAt = null;

    #[ORM\Column]
    private int $extensionCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extensionReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolutionNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'accessRequest', cascade: ['persist', 'remove'])]
    private Collection $documents;

    /** @var Collection<int, StatusHistory> */
    #[ORM\OneToMany(targetEntity: StatusHistory::class, mappedBy: 'accessRequest', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $statusHistory;

    /** @var Collection<int, DeadlineHistory> */
    #[ORM\OneToMany(targetEntity: DeadlineHistory::class, mappedBy: 'accessRequest', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $deadlineHistory;

    /** @var Collection<int, CustomDeadline> */
    #[ORM\OneToMany(targetEntity: CustomDeadline::class, mappedBy: 'accessRequest', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['deadlineAt' => 'ASC'])]
    private Collection $customDeadlines;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->documents = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
        $this->deadlineHistory = new ArrayCollection();
        $this->customDeadlines = new ArrayCollection();
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

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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
            self::STATUS_SENT => 'Enviada',
            self::STATUS_PROCESSING => 'En trámite',
            self::STATUS_GRANTED => 'Concedida',
            self::STATUS_DENIED => 'Denegada',
            self::STATUS_DELAYED => 'Silencio administrativo',
            self::STATUS_PENDING => 'Pendiente de recepción',
            default => $this->status,
        };
    }

    public function getComplaintStatus(): string
    {
        return $this->complaintStatus;
    }

    public function setComplaintStatus(string $complaintStatus): static
    {
        $this->complaintStatus = $complaintStatus;
        return $this;
    }

    public function getComplaintStatusLabel(): string
    {
        return match ($this->complaintStatus) {
            self::COMPLAINT_NONE => 'Sin reclamación',
            self::COMPLAINT_RECLAIMED => 'Reclamada',
            self::COMPLAINT_GRANTED => 'Reclamación estimada',
            self::COMPLAINT_DENIED => 'Reclamación desestimada',
            default => $this->complaintStatus,
        };
    }

    public function getCourtStatus(): string
    {
        return $this->courtStatus;
    }

    public function setCourtStatus(string $courtStatus): static
    {
        $this->courtStatus = $courtStatus;
        return $this;
    }

    public function getCourtStatusLabel(): string
    {
        return match ($this->courtStatus) {
            self::COURT_NONE => 'Sin recurso judicial',
            self::COURT_IN_COURT => 'En vía judicial',
            self::COURT_GRANTED => 'Sentencia favorable',
            self::COURT_DENIED => 'Sentencia desfavorable',
            default => $this->courtStatus,
        };
    }

    public function getThirdPartyStatus(): string
    {
        return $this->thirdPartyStatus;
    }

    public function setThirdPartyStatus(string $thirdPartyStatus): static
    {
        $this->thirdPartyStatus = $thirdPartyStatus;
        return $this;
    }

    public function getThirdPartyStatusLabel(): string
    {
        return match ($this->thirdPartyStatus) {
            self::THIRD_PARTY_NONE => 'Sin afectación a terceros',
            self::THIRD_PARTY_PENDING => 'Alegaciones de terceros pendientes',
            self::THIRD_PARTY_RECEIVED => 'Alegaciones recibidas',
            default => $this->thirdPartyStatus,
        };
    }

    public function getThirdPartyAllegationsStartedAt(): ?\DateTimeImmutable
    {
        return $this->thirdPartyAllegationsStartedAt;
    }

    public function setThirdPartyAllegationsStartedAt(?\DateTimeImmutable $thirdPartyAllegationsStartedAt): static
    {
        $this->thirdPartyAllegationsStartedAt = $thirdPartyAllegationsStartedAt;
        return $this;
    }

    public function getThirdPartyAllegationsDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->thirdPartyAllegationsDeadlineAt;
    }

    public function setThirdPartyAllegationsDeadlineAt(?\DateTimeImmutable $thirdPartyAllegationsDeadlineAt): static
    {
        $this->thirdPartyAllegationsDeadlineAt = $thirdPartyAllegationsDeadlineAt;
        return $this;
    }

    public function getDeadlineSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->deadlineSuspendedAt;
    }

    public function setDeadlineSuspendedAt(?\DateTimeImmutable $deadlineSuspendedAt): static
    {
        $this->deadlineSuspendedAt = $deadlineSuspendedAt;
        return $this;
    }

    public function getSuspendedDaysRemaining(): ?int
    {
        return $this->suspendedDaysRemaining;
    }

    public function setSuspendedDaysRemaining(?int $suspendedDaysRemaining): static
    {
        $this->suspendedDaysRemaining = $suspendedDaysRemaining;
        return $this;
    }

    public function isDeadlineSuspended(): bool
    {
        return $this->deadlineSuspendedAt !== null && $this->thirdPartyStatus === self::THIRD_PARTY_PENDING;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
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

    public function getPublicBody(): PublicBody
    {
        return $this->publicBody;
    }

    public function setPublicBody(PublicBody $publicBody): static
    {
        $this->publicBody = $publicBody;
        return $this;
    }

    public function getOriginalPublicBody(): ?PublicBody
    {
        return $this->originalPublicBody;
    }

    public function setOriginalPublicBody(?PublicBody $originalPublicBody): static
    {
        $this->originalPublicBody = $originalPublicBody;
        return $this;
    }

    public function getRedirectedAt(): ?\DateTimeImmutable
    {
        return $this->redirectedAt;
    }

    public function setRedirectedAt(?\DateTimeImmutable $redirectedAt): static
    {
        $this->redirectedAt = $redirectedAt;
        return $this;
    }

    public function wasRedirected(): bool
    {
        return $this->originalPublicBody !== null;
    }

    public function getApplicableLaw(): ApplicableLaw
    {
        return $this->applicableLaw;
    }

    public function setApplicableLaw(ApplicableLaw $applicableLaw): static
    {
        $this->applicableLaw = $applicableLaw;
        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): static
    {
        $this->acknowledgedAt = $acknowledgedAt;
        return $this;
    }

    public function getProcessingStartedAt(): ?\DateTimeImmutable
    {
        return $this->processingStartedAt;
    }

    public function setProcessingStartedAt(?\DateTimeImmutable $processingStartedAt): static
    {
        $this->processingStartedAt = $processingStartedAt;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getDeadlineAt(): \DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;
        return $this;
    }

    public function getOriginalDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->originalDeadlineAt;
    }

    public function setOriginalDeadlineAt(?\DateTimeImmutable $originalDeadlineAt): static
    {
        $this->originalDeadlineAt = $originalDeadlineAt;
        return $this;
    }

    public function getComplaintDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->complaintDeadlineAt;
    }

    public function setComplaintDeadlineAt(?\DateTimeImmutable $complaintDeadlineAt): static
    {
        $this->complaintDeadlineAt = $complaintDeadlineAt;
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

    public function getExtensionCount(): int
    {
        return $this->extensionCount;
    }

    public function setExtensionCount(int $extensionCount): static
    {
        $this->extensionCount = $extensionCount;
        return $this;
    }

    public function incrementExtensionCount(): static
    {
        $this->extensionCount++;
        return $this;
    }

    public function canExtend(): bool
    {
        return $this->extensionCount < $this->applicableLaw->getMaxExtensions();
    }

    public function getExtensionReason(): ?string
    {
        return $this->extensionReason;
    }

    public function setExtensionReason(?string $extensionReason): static
    {
        $this->extensionReason = $extensionReason;
        return $this;
    }

    public function getResolutionNotes(): ?string
    {
        return $this->resolutionNotes;
    }

    public function setResolutionNotes(?string $resolutionNotes): static
    {
        $this->resolutionNotes = $resolutionNotes;
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

    /** @return Collection<int, Document> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setAccessRequest($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getAccessRequest() === $this) {
                $document->setAccessRequest(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, StatusHistory> */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }

    public function addStatusHistory(StatusHistory $statusHistory): static
    {
        if (!$this->statusHistory->contains($statusHistory)) {
            $this->statusHistory->add($statusHistory);
            $statusHistory->setAccessRequest($this);
        }
        return $this;
    }

    /** @return Collection<int, DeadlineHistory> */
    public function getDeadlineHistory(): Collection
    {
        return $this->deadlineHistory;
    }

    public function addDeadlineHistory(DeadlineHistory $deadlineHistory): static
    {
        if (!$this->deadlineHistory->contains($deadlineHistory)) {
            $this->deadlineHistory->add($deadlineHistory);
            $deadlineHistory->setAccessRequest($this);
        }
        return $this;
    }

    /** @return Collection<int, CustomDeadline> */
    public function getCustomDeadlines(): Collection
    {
        return $this->customDeadlines;
    }

    public function addCustomDeadline(CustomDeadline $customDeadline): static
    {
        if (!$this->customDeadlines->contains($customDeadline)) {
            $this->customDeadlines->add($customDeadline);
            $customDeadline->setAccessRequest($this);
        }
        return $this;
    }

    public function removeCustomDeadline(CustomDeadline $customDeadline): static
    {
        if ($this->customDeadlines->removeElement($customDeadline)) {
            if ($customDeadline->getAccessRequest() === $this) {
                // No need to set null since it's required
            }
        }
        return $this;
    }

    public function isDeadlinePassed(): bool
    {
        return $this->deadlineAt < new \DateTimeImmutable('today');
    }

    public function getDaysUntilDeadline(): int
    {
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->deadlineAt);
        return $interval->invert ? -$interval->days : $interval->days;
    }

    public function isComplaintDeadlinePassed(): bool
    {
        if ($this->complaintDeadlineAt === null) {
            return false;
        }
        return $this->complaintDeadlineAt < new \DateTimeImmutable('today');
    }

    public function getDaysUntilComplaintDeadline(): int
    {
        if ($this->complaintDeadlineAt === null) {
            return 0;
        }
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->complaintDeadlineAt);
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

    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_GRANTED, self::STATUS_DENIED], true)
            || $this->complaintStatus === self::COMPLAINT_RECLAIMED
            || $this->courtStatus === self::COURT_IN_COURT;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
