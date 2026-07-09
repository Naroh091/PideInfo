<?php

namespace App\Entity;

use App\Repository\ResolutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ResolutionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['outcome'], name: 'idx_outcome')]
#[ORM\Index(columns: ['resolution_date'], name: 'idx_resolution_date')]
#[ORM\Index(columns: ['scope'], name: 'idx_resolution_scope')]
#[ORM\Index(columns: ['source'], name: 'idx_resolution_source')]
#[ORM\Index(columns: ['entry_year'], name: 'idx_resolution_entry_year')]
#[ORM\Index(columns: ['entity_type'], name: 'idx_resolution_entity_type')]
#[ORM\UniqueConstraint(columns: ['reference_number', 'source'], name: 'uniq_reference_source')]
class Resolution
{
    // Outcomes
    public const OUTCOME_FAVORABLE = 'favorable';
    public const OUTCOME_UNFAVORABLE = 'unfavorable';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_INADMISSIBLE = 'inadmissible';
    public const OUTCOME_ARCHIVED = 'archivo';
    public const OUTCOME_WITHDRAWAL = 'desistimiento';
    public const OUTCOME_LOSS_OF_PURPOSE = 'perdida_objeto';
    public const OUTCOME_MEDIATION_AGREEMENT = 'acuerdo_mediacion';
    public const OUTCOME_REFERRAL = 'derivacion';
    public const OUTCOME_ROLLBACK = 'retrotraer';
    public const OUTCOME_INHIBITION = 'inhibicion';
    public const OUTCOME_COMPLAINT = 'queja';
    public const OUTCOME_CONSULTATION = 'consulta';
    public const OUTCOME_CLARIFICATION = 'aclaracion';

    // Limits invoked by the administration to deny access (art. 14 Ley 19/2013)
    public const LIMIT_NATIONAL_SECURITY = 'seguridad_nacional';
    public const LIMIT_DEFENSE = 'defensa';
    public const LIMIT_FOREIGN_RELATIONS = 'relaciones_exteriores';
    public const LIMIT_PUBLIC_SAFETY = 'seguridad_publica';
    public const LIMIT_CRIME_PREVENTION = 'prevencion_ilicitos';
    public const LIMIT_JUDICIAL_EQUALITY = 'igualdad_procesos_judiciales';
    public const LIMIT_INSPECTION = 'vigilancia_inspeccion';
    public const LIMIT_ECONOMIC_INTERESTS = 'intereses_economicos';
    public const LIMIT_ECONOMIC_POLICY = 'politica_economica';
    public const LIMIT_PROFESSIONAL_SECRECY = 'secreto_profesional_ip';
    public const LIMIT_DECISION_CONFIDENTIALITY = 'confidencialidad_decision';
    public const LIMIT_ENVIRONMENT = 'medio_ambiente';

    // Inadmission causes invoked by the administration (art. 18 Ley 19/2013)
    public const INADMISSION_IN_PROGRESS = 'en_elaboracion';
    public const INADMISSION_AUXILIARY = 'auxiliar_apoyo';
    public const INADMISSION_REELABORATION = 'reelaboracion';
    public const INADMISSION_NOT_COMPETENT = 'no_competente';
    public const INADMISSION_ABUSIVE = 'repetitiva_abusiva';

    // Source metadata keys for outcome tracking
    public const META_OUTCOME_OVERRIDEN = 'OUTCOME_OVERRIDEN';
    public const META_OUTCOME_RAW = 'OUTCOME_RAW';

    // Sources
    public const SOURCE_CTBG = 'CTBG';
    public const SOURCE_CTBG_LOCAL = 'CTBG_LOCAL';
    public const SOURCE_GAIP = 'GAIP';
    public const SOURCE_CTG = 'CTG';
    public const SOURCE_CVAIP = 'CVAIP';
    public const SOURCE_CTAR = 'CTAR';
    public const SOURCE_CTCYL = 'CTCYL';
    public const SOURCE_CTN = 'CTN';
    public const SOURCE_CTPD = 'CTPD';
    public const SOURCE_CRT = 'CRT';
    public const SOURCE_CTPDA = 'CTPDA';
    public const SOURCE_CVT = 'CVT';
    public const SOURCE_CTCAN = 'CTCAN';
    public const SOURCE_CTRM = 'CTRM';

    // Scopes
    public const SCOPE_NATIONAL = 'national';
    public const SCOPE_AUTONOMOUS = 'autonomous';
    public const SCOPE_LOCAL = 'local';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $referenceNumber;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolutionDate = null;

    #[ORM\Column(length: 50)]
    private string $outcome;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $fullText;

    #[ORM\Column(length: 255)]
    private string $source = self::SOURCE_CTBG;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sourceUrl = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $topics = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $publicBodyName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $keywords = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $claimReason = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $keypoints = null;

    #[ORM\ManyToOne(targetEntity: PublicBody::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PublicBody $publicBody = null;

    #[ORM\ManyToOne(targetEntity: ComplaintOrganism::class, inversedBy: 'resolutions')]
    #[ORM\JoinColumn(nullable: true)]
    private ?ComplaintOrganism $complaintOrganism = null;

    #[ORM\ManyToOne(targetEntity: GeminiBatchJob::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GeminiBatchJob $batchJob = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 20, options: ['default' => self::SCOPE_NATIONAL])]
    private string $scope = self::SCOPE_NATIONAL;

    #[ORM\ManyToOne(targetEntity: AutonomousCommunity::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AutonomousCommunity $autonomousCommunity = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimDate = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $entryYear = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $challengedActs = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $councilCriteria = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pdfStoragePath = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $sourceMetadata = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $infoRequestDate = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $limits = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $inadmissionCauses = null;

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

    public function getReferenceNumber(): string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;
        return $this;
    }

    public function getResolutionDate(): ?\DateTimeImmutable
    {
        return $this->resolutionDate;
    }

    public function setResolutionDate(?\DateTimeImmutable $resolutionDate): static
    {
        $this->resolutionDate = $resolutionDate;
        return $this;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): static
    {
        $this->outcome = $outcome;
        return $this;
    }

    public function getOutcomeLabel(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_FAVORABLE => 'Estimada',
            self::OUTCOME_UNFAVORABLE => 'Desestimada',
            self::OUTCOME_PARTIAL => 'Estimada parcialmente',
            self::OUTCOME_INADMISSIBLE => 'Inadmitida',
            self::OUTCOME_ARCHIVED => 'Archivada',
            self::OUTCOME_WITHDRAWAL => 'Desistimiento',
            self::OUTCOME_LOSS_OF_PURPOSE => 'Pérdida de objeto',
            self::OUTCOME_MEDIATION_AGREEMENT => 'Acuerdo de mediación',
            self::OUTCOME_REFERRAL => 'Derivada',
            self::OUTCOME_ROLLBACK => 'Retrotraer',
            self::OUTCOME_INHIBITION => 'Inhibición',
            self::OUTCOME_COMPLAINT => 'Queja',
            self::OUTCOME_CONSULTATION => 'Consulta',
            self::OUTCOME_CLARIFICATION => 'Aclaración',
            default => $this->outcome,
        };
    }

    public function isFavorable(): bool
    {
        return in_array($this->outcome, self::getFavorableOutcomes(), true);
    }

    /**
     * Outcomes counted as a win for the claimant when computing success rates.
     *
     * @return list<string>
     */
    public static function getFavorableOutcomes(): array
    {
        return [self::OUTCOME_FAVORABLE, self::OUTCOME_PARTIAL, self::OUTCOME_MEDIATION_AGREEMENT];
    }

    /**
     * Outcomes counted as a loss. Everything else (archivo, desistimiento…) is
     * not decisive and stays out of the success rate.
     *
     * @return list<string>
     */
    public static function getUnfavorableOutcomes(): array
    {
        return [self::OUTCOME_UNFAVORABLE, self::OUTCOME_INADMISSIBLE];
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getFullText(): string
    {
        return $this->fullText;
    }

    public function setFullText(string $fullText): static
    {
        $this->fullText = $fullText;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    /** @return array<string>|null */
    public function getTopics(): ?array
    {
        return $this->topics;
    }

    /** @param array<string>|null $topics */
    public function setTopics(?array $topics): static
    {
        $this->topics = $topics;
        return $this;
    }

    public function getPublicBody(): ?PublicBody
    {
        return $this->publicBody;
    }

    public function setPublicBody(?PublicBody $publicBody): static
    {
        $this->publicBody = $publicBody;
        return $this;
    }

    public function getPublicBodyName(): ?string
    {
        return $this->publicBodyName;
    }

    public function setPublicBodyName(?string $publicBodyName): static
    {
        $this->publicBodyName = $publicBodyName;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    /** @return array<string>|null */
    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    /** @param array<string>|null $keywords */
    public function setKeywords(?array $keywords): static
    {
        $this->keywords = $keywords;
        return $this;
    }

    public function getClaimReason(): ?string
    {
        return $this->claimReason;
    }

    public function setClaimReason(?string $claimReason): static
    {
        $this->claimReason = $claimReason;
        return $this;
    }

    /** @return array<string>|null */
    public function getKeypoints(): ?array
    {
        return $this->keypoints;
    }

    /** @param array<string>|null $keypoints */
    public function setKeypoints(?array $keypoints): static
    {
        $this->keypoints = $keypoints;
        return $this;
    }

    public function getComplaintOrganism(): ?ComplaintOrganism
    {
        return $this->complaintOrganism;
    }

    public function setComplaintOrganism(?ComplaintOrganism $complaintOrganism): static
    {
        $this->complaintOrganism = $complaintOrganism;
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

    // --- New field accessors ---

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getScopeLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_NATIONAL => 'Nacional',
            self::SCOPE_AUTONOMOUS => 'Autonómico',
            self::SCOPE_LOCAL => 'Local',
            default => $this->scope,
        };
    }

    public function getAutonomousCommunity(): ?AutonomousCommunity
    {
        return $this->autonomousCommunity;
    }

    public function setAutonomousCommunity(?AutonomousCommunity $autonomousCommunity): static
    {
        $this->autonomousCommunity = $autonomousCommunity;
        return $this;
    }

    public function getClaimDate(): ?\DateTimeImmutable
    {
        return $this->claimDate;
    }

    public function setClaimDate(?\DateTimeImmutable $claimDate): static
    {
        $this->claimDate = $claimDate;
        return $this;
    }

    public function getEntryYear(): ?int
    {
        return $this->entryYear;
    }

    public function setEntryYear(?int $entryYear): static
    {
        $this->entryYear = $entryYear;
        return $this;
    }

    /** @return array<string>|null */
    public function getChallengedActs(): ?array
    {
        return $this->challengedActs;
    }

    /** @param array<string>|null $challengedActs */
    public function setChallengedActs(?array $challengedActs): static
    {
        $this->challengedActs = $challengedActs;
        return $this;
    }

    /** @return array<string>|null */
    public function getCouncilCriteria(): ?array
    {
        return $this->councilCriteria;
    }

    /** @param array<string>|null $councilCriteria */
    public function setCouncilCriteria(?array $councilCriteria): static
    {
        $this->councilCriteria = $councilCriteria;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(?string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getPdfStoragePath(): ?string
    {
        return $this->pdfStoragePath;
    }

    public function setPdfStoragePath(?string $pdfStoragePath): static
    {
        $this->pdfStoragePath = $pdfStoragePath;
        return $this;
    }

    /**
     * Days the body took to resolve the complaint, or null when it cannot be known.
     *
     * DateInterval::$days is unsigned, so sources that publish a resolution date earlier
     * than the claim date (bad data, ~1% of the corpus) would otherwise report a
     * plausible-looking positive duration. Those rows have no meaningful value.
     */
    public function getDaysToResolve(): ?int
    {
        if ($this->claimDate === null || $this->resolutionDate === null) {
            return null;
        }
        if ($this->resolutionDate < $this->claimDate) {
            return null;
        }
        return $this->claimDate->diff($this->resolutionDate)->days;
    }

    /** @return array<string, mixed>|null */
    public function getSourceMetadata(): ?array
    {
        return $this->sourceMetadata;
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setSourceMetadata(?array $sourceMetadata): static
    {
        $this->sourceMetadata = $sourceMetadata;
        return $this;
    }

    public function getBatchJob(): ?GeminiBatchJob
    {
        return $this->batchJob;
    }

    public function setBatchJob(?GeminiBatchJob $batchJob): static
    {
        $this->batchJob = $batchJob;
        return $this;
    }

    public function getInfoRequestDate(): ?\DateTimeImmutable
    {
        return $this->infoRequestDate;
    }

    public function setInfoRequestDate(?\DateTimeImmutable $infoRequestDate): static
    {
        $this->infoRequestDate = $infoRequestDate;
        return $this;
    }

    /** @return array<string>|null */
    public function getLimits(): ?array
    {
        return $this->limits;
    }

    /** @param array<string>|null $limits */
    public function setLimits(?array $limits): static
    {
        $this->limits = $limits;
        return $this;
    }

    /** @return array<string>|null */
    public function getInadmissionCauses(): ?array
    {
        return $this->inadmissionCauses;
    }

    /** @param array<string>|null $inadmissionCauses */
    public function setInadmissionCauses(?array $inadmissionCauses): static
    {
        $this->inadmissionCauses = $inadmissionCauses;
        return $this;
    }

    /**
     * @return array<string, string> Map of LIMIT_* constants to their Spanish labels.
     */
    public static function getLimitLabels(): array
    {
        return [
            self::LIMIT_NATIONAL_SECURITY => 'La seguridad nacional',
            self::LIMIT_DEFENSE => 'La defensa',
            self::LIMIT_FOREIGN_RELATIONS => 'Las relaciones exteriores',
            self::LIMIT_PUBLIC_SAFETY => 'La seguridad pública',
            self::LIMIT_CRIME_PREVENTION => 'La prevención, investigación y sanción de ilícitos penales, administrativos o disciplinarios',
            self::LIMIT_JUDICIAL_EQUALITY => 'La igualdad de las partes en los procesos judiciales y la tutela judicial efectiva',
            self::LIMIT_INSPECTION => 'Las funciones administrativas de vigilancia, inspección y control',
            self::LIMIT_ECONOMIC_INTERESTS => 'Los intereses económicos y comerciales',
            self::LIMIT_ECONOMIC_POLICY => 'La política económica y monetaria',
            self::LIMIT_PROFESSIONAL_SECRECY => 'El secreto profesional y la propiedad intelectual e industrial',
            self::LIMIT_DECISION_CONFIDENTIALITY => 'La garantía de la confidencialidad o el secreto en procesos de toma de decisión',
            self::LIMIT_ENVIRONMENT => 'La protección del medio ambiente',
        ];
    }

    /**
     * @return array<string, string> Map of INADMISSION_* constants to their Spanish labels.
     */
    public static function getInadmissionCauseLabels(): array
    {
        return [
            self::INADMISSION_IN_PROGRESS => 'Información en curso de elaboración o de publicación general',
            self::INADMISSION_AUXILIARY => 'Información auxiliar o de apoyo (notas, borradores, opiniones, resúmenes, comunicaciones e informes internos)',
            self::INADMISSION_REELABORATION => 'Información para cuya divulgación sea necesaria una acción previa de reelaboración',
            self::INADMISSION_NOT_COMPETENT => 'Dirigida a un órgano que no es competente y se desconoce el competente',
            self::INADMISSION_ABUSIVE => 'Solicitudes manifiestamente repetitivas o de carácter abusivo',
        ];
    }

    /**
     * @return array<string, string> Map of OUTCOME_* constants to their Spanish labels.
     */
    public static function getOutcomeLabels(): array
    {
        return [
            self::OUTCOME_FAVORABLE => 'Estimada',
            self::OUTCOME_UNFAVORABLE => 'Desestimada',
            self::OUTCOME_PARTIAL => 'Estimada parcialmente',
            self::OUTCOME_INADMISSIBLE => 'Inadmitida',
            self::OUTCOME_ARCHIVED => 'Archivada',
            self::OUTCOME_WITHDRAWAL => 'Desistimiento',
            self::OUTCOME_LOSS_OF_PURPOSE => 'Pérdida de objeto',
            self::OUTCOME_MEDIATION_AGREEMENT => 'Acuerdo de mediación',
            self::OUTCOME_REFERRAL => 'Derivada',
            self::OUTCOME_ROLLBACK => 'Retrotraer',
            self::OUTCOME_INHIBITION => 'Inhibición',
            self::OUTCOME_COMPLAINT => 'Queja',
            self::OUTCOME_CONSULTATION => 'Consulta',
            self::OUTCOME_CLARIFICATION => 'Aclaración',
        ];
    }

    public function __toString(): string
    {
        return $this->referenceNumber . ($this->resolutionDate ? ' - ' . $this->resolutionDate->format('d/m/Y') : '');
    }
}
