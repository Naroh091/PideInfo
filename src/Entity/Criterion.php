<?php

namespace App\Entity;

use App\Repository\CriterionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Interpretive criterion ("criterio interpretativo") issued by a transparency
 * council. Models the doctrinal documents the AI prompt builder injects as
 * grounding when drafting a complaint.
 *
 * Parallel in shape to {@see Resolution}: each row stores the raw text we
 * later vectorise into the `ai_ctbg_criteria` Postgres store. The vector
 * store keeps embeddings keyed by chunk; this entity keeps the source of
 * truth (text + provenance) so re-vectorising is just a SQL pass over the
 * table instead of re-reading PDFs from disk.
 */
#[ORM\Entity(repositoryClass: CriterionRepository::class)]
#[ORM\Index(columns: ['source'], name: 'idx_criterion_source')]
#[ORM\Index(columns: ['year'], name: 'idx_criterion_year')]
#[ORM\Index(columns: ['scope'], name: 'idx_criterion_scope')]
#[ORM\UniqueConstraint(columns: ['reference_number', 'source'], name: 'uniq_criterion_reference_source')]
class Criterion
{
    // Sources mirror Resolution::SOURCE_* — keep them aligned so a future
    // shared trait or shared enum can absorb both.
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

    public const SCOPE_NATIONAL = 'national';
    public const SCOPE_AUTONOMOUS = 'autonomous';
    public const SCOPE_LOCAL = 'local';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * Canonical identifier as published by the issuing council (e.g.
     * "CI/004/2015", "C1/2015"). Together with `source` forms the unique
     * key — the same number can exist across different councils.
     */
    #[ORM\Column(length: 100)]
    private string $referenceNumber;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $year = null;

    /** Short human-readable subject ("Acceso a RPT y retribuciones"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $topic = null;

    /** Optional one-paragraph summary used as grounding context in prompts. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** Full body of the criterion. This is the field we vectorise. */
    #[ORM\Column(type: Types::TEXT)]
    private string $fullText;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $keypoints = null;

    #[ORM\Column(length: 50, options: ['default' => self::SOURCE_CTBG])]
    private string $source = self::SOURCE_CTBG;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pdfStoragePath = null;

    #[ORM\ManyToOne(targetEntity: ComplaintOrganism::class, inversedBy: 'criteria')]
    #[ORM\JoinColumn(nullable: true)]
    private ?ComplaintOrganism $complaintOrganism = null;

    #[ORM\ManyToOne(targetEntity: AutonomousCommunity::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AutonomousCommunity $autonomousCommunity = null;

    #[ORM\Column(length: 20, options: ['default' => self::SCOPE_NATIONAL])]
    private string $scope = self::SCOPE_NATIONAL;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

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

    public function getReferenceNumber(): string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;
        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function setTopic(?string $topic): static
    {
        $this->topic = $topic;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
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

    public function getPdfStoragePath(): ?string
    {
        return $this->pdfStoragePath;
    }

    public function setPdfStoragePath(?string $pdfStoragePath): static
    {
        $this->pdfStoragePath = $pdfStoragePath;
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

    public function getAutonomousCommunity(): ?AutonomousCommunity
    {
        return $this->autonomousCommunity;
    }

    public function setAutonomousCommunity(?AutonomousCommunity $autonomousCommunity): static
    {
        $this->autonomousCommunity = $autonomousCommunity;
        return $this;
    }

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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->referenceNumber . ($this->topic ? ' — ' . $this->topic : '');
    }
}
