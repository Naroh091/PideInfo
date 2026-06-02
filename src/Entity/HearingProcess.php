<?php

namespace App\Entity;

use App\Repository\HearingProcessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Trámite de audiencia abierto por el organismo de transparencia dentro de una
 * reclamación: ventana de N días (hábiles o naturales) para que el reclamante
 * presente alegaciones. Se crea al procesar un documento tipo `audiencia` que
 * trae hearing_days en su análisis.
 */
#[ORM\Entity(repositoryClass: HearingProcessRepository::class)]
#[ORM\Table(name: 'hearing_process')]
#[ORM\Index(columns: ['complaint_id', 'end_date'], name: 'idx_hearing_complaint_end')]
class HearingProcess
{
    public const DAYS_TYPE_BUSINESS = 'business';
    public const DAYS_TYPE_CALENDAR = 'calendar';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequestComplaint::class, inversedBy: 'hearingProcesses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequestComplaint $complaint;

    /** Documento que abrió el trámite — clave de idempotencia al reprocesar. */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $triggerDocument = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $hearingDays;

    #[ORM\Column(length: 16)]
    private string $hearingDaysType = self::DAYS_TYPE_BUSINESS;

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

    public function getComplaint(): AccessRequestComplaint
    {
        return $this->complaint;
    }

    public function setComplaint(AccessRequestComplaint $complaint): static
    {
        $this->complaint = $complaint;
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

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getHearingDays(): int
    {
        return $this->hearingDays;
    }

    public function setHearingDays(int $hearingDays): static
    {
        $this->hearingDays = $hearingDays;
        return $this;
    }

    public function getHearingDaysType(): string
    {
        return $this->hearingDaysType;
    }

    public function setHearingDaysType(string $hearingDaysType): static
    {
        $this->hearingDaysType = $hearingDaysType;
        return $this;
    }

    public function getHearingDaysTypeLabel(): string
    {
        return $this->hearingDaysType === self::DAYS_TYPE_CALENDAR ? 'días naturales' : 'días hábiles';
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** El plazo sigue abierto (la fecha límite es hoy o futura). */
    public function isActive(): bool
    {
        return $this->endDate >= new \DateTimeImmutable('today');
    }

    public function getDaysUntilEnd(): int
    {
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->endDate);
        return $interval->invert ? -$interval->days : $interval->days;
    }
}
