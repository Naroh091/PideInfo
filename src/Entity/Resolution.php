<?php

namespace App\Entity;

use App\Repository\ResolutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ResolutionRepository::class)]
#[ORM\Index(columns: ['outcome'], name: 'idx_outcome')]
#[ORM\Index(columns: ['resolution_date'], name: 'idx_resolution_date')]
class Resolution
{
    public const OUTCOME_FAVORABLE = 'favorable';
    public const OUTCOME_UNFAVORABLE = 'unfavorable';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_INADMISSIBLE = 'inadmissible';

    public const SOURCE_CTBG = 'CTBG';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $referenceNumber;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $resolutionDate;

    #[ORM\Column(length: 50)]
    private string $outcome;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT)]
    private string $fullText;

    /**
     * Vector embedding stored as binary blob
     * MariaDB 11.4+ uses VEC_FromText() and VEC_ToText() for vector operations
     */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private mixed $embedding = null;

    #[ORM\Column(length: 255)]
    private string $source = self::SOURCE_CTBG;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceUrl = null;

    /** @var array<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topics = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicBodyName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $embeddedAt = null;

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

    public function getResolutionDate(): \DateTimeImmutable
    {
        return $this->resolutionDate;
    }

    public function setResolutionDate(\DateTimeImmutable $resolutionDate): static
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
            default => $this->outcome,
        };
    }

    public function isFavorable(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_FAVORABLE, self::OUTCOME_PARTIAL], true);
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

    public function getEmbedding(): mixed
    {
        return $this->embedding;
    }

    public function setEmbedding(mixed $embedding): static
    {
        $this->embedding = $embedding;
        $this->embeddedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding !== null;
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

    public function getPublicBodyName(): ?string
    {
        return $this->publicBodyName;
    }

    public function setPublicBodyName(?string $publicBodyName): static
    {
        $this->publicBodyName = $publicBodyName;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEmbeddedAt(): ?\DateTimeImmutable
    {
        return $this->embeddedAt;
    }

    public function __toString(): string
    {
        return $this->referenceNumber . ' - ' . $this->resolutionDate->format('d/m/Y');
    }
}
