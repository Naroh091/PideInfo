<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LegalArticleRepository;
use App\Service\Legal\TrackedNorms;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One article (or disposición adicional/transitoria/final, or anexo) of a *tracked* norm.
 * Source of truth for the Elasticsearch `laws` index.
 *
 * Rows are written in bulk by LegalArticleRepository::replaceForNorm() through DBAL, NOT
 * through the ORM: the LCSP alone has ~350 articles and the UnitOfWork buys us nothing.
 * That is also why there is no Doctrine index listener (see docs/legal-framework.md):
 * indexing is triggered explicitly, per norm, by LegalArticleIndexer.
 *
 * The getNorm*() accessors delegate to the parent norm so the FOSElastica mapping can read
 * everything off this object without a custom transformer.
 */
#[ORM\Entity(repositoryClass: LegalArticleRepository::class)]
#[ORM\Table(name: 'legal_article')]
#[ORM\UniqueConstraint(name: 'uniq_legal_article_norm_anchor', columns: ['norm_id', 'anchor'])]
#[ORM\Index(name: 'idx_legal_article_boe_position', columns: ['boe_id', 'position'])]
#[ORM\Index(name: 'idx_legal_article_number', columns: ['boe_id', 'number_int', 'number_suffix'])]
#[ORM\HasLifecycleCallbacks]
class LegalArticle
{
    public const KIND_ARTICLE = 'article';
    public const KIND_PREAMBLE = 'preamble';
    public const KIND_ADDITIONAL = 'additional';
    public const KIND_TRANSITIONAL = 'transitional';
    public const KIND_DEROGATORY = 'derogatory';
    public const KIND_FINAL = 'final';
    public const KIND_ANNEX = 'annex';
    public const KIND_OTHER = 'other';

    /** Human label for the citation, by kind. */
    private const KIND_LABELS = [
        self::KIND_ARTICLE => 'art.',
        self::KIND_ADDITIONAL => 'Disposición adicional',
        self::KIND_TRANSITIONAL => 'Disposición transitoria',
        self::KIND_DEROGATORY => 'Disposición derogatoria',
        self::KIND_FINAL => 'Disposición final',
        self::KIND_ANNEX => 'Anexo',
        self::KIND_PREAMBLE => 'Preámbulo',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: LegalNorm::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(name: 'norm_id', nullable: false, onDelete: 'CASCADE')]
    private LegalNorm $norm;

    /** Denormalised: the citation and the ES document need it without a JOIN. */
    #[ORM\Column(length: 40)]
    private string $boeId;

    /** Stable slug within the norm: `articulo-118`, `articulo-118-bis`, `disposicion-adicional-primera`. */
    #[ORM\Column(length: 80)]
    private string $anchor;

    #[ORM\Column(length: 24)]
    private string $kind = self::KIND_ARTICLE;

    /** As printed: "118", "118 bis", "primera", "único". */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $number = null;

    /** Numeric part, for ordering and for ranges like "14-16". Ordinals ("primera") map to 1. */
    #[ORM\Column(nullable: true)]
    private ?int $numberInt = null;

    /** "", "bis", "ter", "quater"… Ordered by SUFFIX_ORDER so 118 < 118 bis < 119. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $numberSuffix = null;

    /** Document order within the norm. */
    #[ORM\Column]
    private int $position = 0;

    /** The article's rúbrica, e.g. "Expediente de contratación en contratos menores". */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $heading = null;

    /** "LIBRO II › TÍTULO I › CAPÍTULO I › Sección 2.ª" — what makes the citation locatable. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $breadcrumb = null;

    /** @var array<string, string>|null Same, structured: {libro, titulo, capitulo, seccion}. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $breadcrumbJson = null;

    /** Literal text, with the <small> amendment notes stripped out. */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** The <small> notes ("Se modifica por…"), kept apart so they never pollute a quotation. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentNotes = null;

    /**
     * Repealed articles are STORED, not skipped: if the model asks for art. 30 it must read
     * "DEROGADO", not get silence. They are excluded from BM25 by default.
     */
    #[ORM\Column]
    private bool $repealed = false;

    #[ORM\Column]
    private int $charCount = 0;

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

    /** "art. 118 bis" · "Disposición adicional primera" · "Anexo I". */
    public function getCitationLabel(): string
    {
        $label = self::KIND_LABELS[$this->kind] ?? '';

        if ($this->kind === self::KIND_PREAMBLE || $this->number === null || $this->number === '') {
            return $label !== '' ? $label : 'Fragmento';
        }

        return trim($label . ' ' . $this->number);
    }

    // --- Delegations to the norm: the FOSElastica mapping reads them straight off here. ---

    public function getNormTitle(): string
    {
        return $this->norm->getTitle();
    }

    /** "LCSP", "LBRL"… Only tracked norms have one, and only tracked norms are indexed. */
    public function getNormAlias(): ?string
    {
        return TrackedNorms::alias($this->boeId);
    }

    public function getNormShortLabel(): string
    {
        return $this->norm->getShortLabel();
    }

    public function getOfficialNumber(): ?string
    {
        return $this->norm->getOfficialNumber();
    }

    public function getJurisdiction(): string
    {
        return $this->norm->getJurisdiction();
    }

    /** @return list<string> */
    public function getSubjects(): array
    {
        return $this->norm->getSubjects();
    }

    // --- Plain accessors ---

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNorm(): LegalNorm
    {
        return $this->norm;
    }

    public function setNorm(LegalNorm $norm): static
    {
        $this->norm = $norm;
        $this->boeId = $norm->getBoeId();

        return $this;
    }

    public function getBoeId(): string
    {
        return $this->boeId;
    }

    public function setBoeId(string $boeId): static
    {
        $this->boeId = $boeId;

        return $this;
    }

    public function getAnchor(): string
    {
        return $this->anchor;
    }

    public function setAnchor(string $anchor): static
    {
        $this->anchor = $anchor;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getNumberInt(): ?int
    {
        return $this->numberInt;
    }

    public function setNumberInt(?int $numberInt): static
    {
        $this->numberInt = $numberInt;

        return $this;
    }

    public function getNumberSuffix(): ?string
    {
        return $this->numberSuffix;
    }

    public function setNumberSuffix(?string $numberSuffix): static
    {
        $this->numberSuffix = $numberSuffix;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function setHeading(?string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function getBreadcrumb(): ?string
    {
        return $this->breadcrumb;
    }

    public function setBreadcrumb(?string $breadcrumb): static
    {
        $this->breadcrumb = $breadcrumb;

        return $this;
    }

    /** @return array<string, string> */
    public function getBreadcrumbJson(): array
    {
        return $this->breadcrumbJson ?? [];
    }

    /** @param array<string, string>|null $breadcrumbJson */
    public function setBreadcrumbJson(?array $breadcrumbJson): static
    {
        $this->breadcrumbJson = $breadcrumbJson;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        $this->charCount = mb_strlen($content);

        return $this;
    }

    public function getContentNotes(): ?string
    {
        return $this->contentNotes;
    }

    public function setContentNotes(?string $contentNotes): static
    {
        $this->contentNotes = $contentNotes;

        return $this;
    }

    public function isRepealed(): bool
    {
        return $this->repealed;
    }

    public function setRepealed(bool $repealed): static
    {
        $this->repealed = $repealed;

        return $this;
    }

    public function getCharCount(): int
    {
        return $this->charCount;
    }

    public function setCharCount(int $charCount): static
    {
        $this->charCount = $charCount;

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
}
