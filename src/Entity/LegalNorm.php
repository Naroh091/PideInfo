<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LegalNormRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row per norm in the legalize-es repo (the whole BOE catalogue: `es/` plus the 17
 * `es-XX/` autonomous directories). Populated from the file's YAML frontmatter only —
 * the body is never read here, because the daily sync walks tens of thousands of files
 * and some of them are 1.7 MB.
 *
 * Only norms with `tracked = true` (see TrackedNorms) get their articulado extracted into
 * LegalArticle and indexed in Elasticsearch. Everything else is still fully reachable:
 * `read_law_articles` parses the .md from disk on demand.
 *
 * The `search_vector` tsvector column exists in the database (GENERATED ALWAYS) but is
 * deliberately NOT mapped here: Doctrine has no tsvector type, and the only consumer is
 * LegalNormRepository::searchByName(), which uses native SQL.
 */
#[ORM\Entity(repositoryClass: LegalNormRepository::class)]
#[ORM\Table(name: 'legal_norm')]
#[ORM\UniqueConstraint(name: 'uniq_legal_norm_boe', columns: ['boe_id'])]
#[ORM\Index(name: 'idx_legal_norm_jurisdiction', columns: ['jurisdiction'])]
#[ORM\Index(name: 'idx_legal_norm_number_rank', columns: ['official_number', 'norm_rank'])]
#[ORM\HasLifecycleCallbacks]
class LegalNorm
{
    public const PARSE_OK = 'ok';
    public const PARSE_NO_ARTICLES = 'no_articles';
    public const PARSE_ERROR = 'error';

    public const JURISDICTION_STATE = 'es';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Official BOE identifier, e.g. BOE-A-2017-12902. Also the filename in legalize-es. */
    #[ORM\Column(length: 40)]
    private string $boeId;

    /** `es` for state law, `es-ct`/`es-an`/… (ELI codes) for autonomous law. */
    #[ORM\Column(length: 8)]
    private string $jurisdiction;

    /** Path inside the legalize-es checkout, e.g. `es/BOE-A-2017-12902.md`. */
    #[ORM\Column(length: 255)]
    private string $relativePath;

    #[ORM\Column(type: Types::TEXT)]
    private string $title;

    /** e.g. "9/2017". */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $officialNumber = null;

    /** `rank` is a reserved SQL word, hence the column name. Values: ley, ley_organica, real_decreto… */
    #[ORM\Column(name: 'norm_rank', length: 60, nullable: true)]
    private ?string $normRank = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $rankCode = null;

    /** Frontmatter `scope`, e.g. "Estatal". Free text from the BOE, not our own scope vocabulary. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $department = null;

    /** Frontmatter `status`, e.g. "in_force". */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $consolidationStatus = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publicationDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $enactmentDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUpdated = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlEli = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlHtmlConsolidada = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlPdf = null;

    /** @var list<string>|null BOE subject descriptors. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $subjects = null;

    /** Projection of TrackedNorms: whether this norm's articulado is extracted and indexed. */
    #[ORM\Column]
    private bool $tracked = false;

    /** sha256 of the .md file at the time the articulado was last extracted. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column]
    private int $articleCount = 0;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $parseStatus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $articlesIndexedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, LegalArticle> */
    #[ORM\OneToMany(mappedBy: 'norm', targetEntity: LegalArticle::class, orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $articles;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->articles = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * How the model and the citation formatter refer to the norm: "Ley 9/2017",
     * "RD 2568/1986". Falls back to a truncated title when the frontmatter has no number.
     */
    public function getShortLabel(): string
    {
        if ($this->officialNumber === null || $this->officialNumber === '') {
            return mb_strimwidth($this->title, 0, 80, '…');
        }

        $prefix = match ($this->normRank) {
            'ley' => 'Ley',
            'ley_organica' => 'LO',
            'real_decreto' => 'RD',
            'real_decreto_legislativo' => 'RDLeg',
            'real_decreto_ley' => 'RDL',
            'constitucion' => 'CE',
            'orden' => 'Orden',
            default => '',
        };

        return trim($prefix . ' ' . $this->officialNumber);
    }

    public function isStateLaw(): bool
    {
        return $this->jurisdiction === self::JURISDICTION_STATE;
    }

    public function isTracked(): bool
    {
        return $this->tracked;
    }

    public function hasArticles(): bool
    {
        return $this->articleCount > 0;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getJurisdiction(): string
    {
        return $this->jurisdiction;
    }

    public function setJurisdiction(string $jurisdiction): static
    {
        $this->jurisdiction = $jurisdiction;

        return $this;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function setRelativePath(string $relativePath): static
    {
        $this->relativePath = $relativePath;

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

    public function getOfficialNumber(): ?string
    {
        return $this->officialNumber;
    }

    public function setOfficialNumber(?string $officialNumber): static
    {
        $this->officialNumber = $officialNumber;

        return $this;
    }

    public function getNormRank(): ?string
    {
        return $this->normRank;
    }

    public function setNormRank(?string $normRank): static
    {
        $this->normRank = $normRank;

        return $this;
    }

    public function getRankCode(): ?string
    {
        return $this->rankCode;
    }

    public function setRankCode(?string $rankCode): static
    {
        $this->rankCode = $rankCode;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getConsolidationStatus(): ?string
    {
        return $this->consolidationStatus;
    }

    public function setConsolidationStatus(?string $consolidationStatus): static
    {
        $this->consolidationStatus = $consolidationStatus;

        return $this;
    }

    public function getPublicationDate(): ?\DateTimeImmutable
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(?\DateTimeImmutable $publicationDate): static
    {
        $this->publicationDate = $publicationDate;

        return $this;
    }

    public function getEnactmentDate(): ?\DateTimeImmutable
    {
        return $this->enactmentDate;
    }

    public function setEnactmentDate(?\DateTimeImmutable $enactmentDate): static
    {
        $this->enactmentDate = $enactmentDate;

        return $this;
    }

    public function getLastUpdated(): ?\DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(?\DateTimeImmutable $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;

        return $this;
    }

    public function getUrlEli(): ?string
    {
        return $this->urlEli;
    }

    public function setUrlEli(?string $urlEli): static
    {
        $this->urlEli = $urlEli;

        return $this;
    }

    public function getUrlHtmlConsolidada(): ?string
    {
        return $this->urlHtmlConsolidada;
    }

    public function setUrlHtmlConsolidada(?string $url): static
    {
        $this->urlHtmlConsolidada = $url;

        return $this;
    }

    public function getUrlPdf(): ?string
    {
        return $this->urlPdf;
    }

    public function setUrlPdf(?string $urlPdf): static
    {
        $this->urlPdf = $urlPdf;

        return $this;
    }

    /** @return list<string> */
    public function getSubjects(): array
    {
        return $this->subjects ?? [];
    }

    /** @param list<string>|null $subjects */
    public function setSubjects(?array $subjects): static
    {
        $this->subjects = $subjects;

        return $this;
    }

    public function setTracked(bool $tracked): static
    {
        $this->tracked = $tracked;

        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getArticleCount(): int
    {
        return $this->articleCount;
    }

    public function setArticleCount(int $articleCount): static
    {
        $this->articleCount = $articleCount;

        return $this;
    }

    public function getParseStatus(): ?string
    {
        return $this->parseStatus;
    }

    public function setParseStatus(?string $parseStatus): static
    {
        $this->parseStatus = $parseStatus;

        return $this;
    }

    public function getArticlesIndexedAt(): ?\DateTimeImmutable
    {
        return $this->articlesIndexedAt;
    }

    public function setArticlesIndexedAt(?\DateTimeImmutable $at): static
    {
        $this->articlesIndexedAt = $at;

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

    /** @return Collection<int, LegalArticle> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
