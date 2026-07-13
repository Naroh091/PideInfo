<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JudgmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A judicial ruling on a recurso against a transparency-council resolution: the case law that
 * shapes the right of access, and — through the `resolutions` link — the veto that stops the
 * agent from citing an annulled resolution as favourable precedent.
 *
 * THREE outcome fields, on purpose, because conflating them produces the most expensive legal
 * error available. The plaintiff is usually the ADMINISTRATION appealing a resolution that
 * sided with the citizen, so a judgment that DISMISSES the recurso (outcome=desestimatoria) is
 * usually PRO-ACCESS:
 *
 *   - outcome             what happened to the RECURSO (estimatoria/desestimatoria/…)
 *   - resolutionEffect    what happened to the CTBG RESOLUTION (confirma/anula/…)
 *   - transparencyStance  what it means for the right of access — the field the agent uses
 *
 * The natural key is (referenceNumber, source), like Resolution. The referenceNumber is built
 * deterministically by the reader (JCCA9/60/2016, AN/63/2016, TS/1547/2017) because the bare
 * sentencia number is NOT unique: the live XLSX has two 60/2016 from different juzgados. ECLI
 * cannot be the ingest key — the XLSX does not publish it; it is only known after analysing
 * the PDF — so it lives in its own column with a partial unique index.
 */
#[ORM\Entity(repositoryClass: JudgmentRepository::class)]
#[ORM\Table(name: 'judgment')]
#[ORM\UniqueConstraint(name: 'uniq_judgment_reference_source', columns: ['reference_number', 'source'])]
#[ORM\Index(name: 'idx_judgment_court', columns: ['court'])]
#[ORM\Index(name: 'idx_judgment_stance', columns: ['transparency_stance'])]
#[ORM\Index(name: 'idx_judgment_final', columns: ['is_final'])]
#[ORM\HasLifecycleCallbacks]
class Judgment
{
    public const SOURCE_CTBG_RECURSOS = 'ctbg_recursos';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CENDOJ = 'cendoj';

    public const COURT_JCCA = 'JCCA';   // Juzgado Central de lo Contencioso-Administrativo
    public const COURT_AN = 'AN';       // Audiencia Nacional
    public const COURT_TS = 'TS';       // Tribunal Supremo
    public const COURT_TSJ = 'TSJ';     // Tribunales Superiores de Justicia (carga manual)
    public const COURT_OTHER = 'OTHER';

    public const INSTANCE_FIRST = 'primera_instancia';
    public const INSTANCE_APPEAL = 'apelacion';
    public const INSTANCE_CASSATION = 'casacion';

    /** Sentido del fallo respecto del RECURSO. */
    public const OUTCOME_ESTIMATORIA = 'estimatoria';
    public const OUTCOME_ESTIMATORIA_PARCIAL = 'estimatoria_parcial';
    public const OUTCOME_DESESTIMATORIA = 'desestimatoria';
    public const OUTCOME_INADMISION = 'inadmision';
    public const OUTCOME_ARCHIVO = 'archivo';
    public const OUTCOME_DESISTIMIENTO = 'desistimiento';

    /** Efecto sobre la RESOLUCIÓN del consejo de transparencia. */
    public const EFFECT_CONFIRMA = 'confirma';
    public const EFFECT_ANULA = 'anula';
    public const EFFECT_ANULA_PARCIAL = 'anula_parcial';
    public const EFFECT_RETROTRAE = 'retrotrae';

    /** Lo que significa para el derecho de acceso — el campo que consume el agente. */
    public const STANCE_PRO_ACCESS = 'pro_acceso';
    public const STANCE_ANTI_ACCESS = 'contra_acceso';
    public const STANCE_NEUTRAL = 'neutro';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Deterministic: court + sentencia number, e.g. "JCCA9/60/2016", "TS/1547/2017". */
    #[ORM\Column(length: 60)]
    private string $referenceNumber;

    #[ORM\Column(length: 30)]
    private string $source = self::SOURCE_CTBG_RECURSOS;

    #[ORM\Column(length: 10)]
    private string $court = self::COURT_JCCA;

    /** "9" in "Juzgado Central de lo Contencioso-Administrativo nº 9". */
    #[ORM\Column(nullable: true)]
    private ?int $courtNumber = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $courtName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $chamber = null;

    #[ORM\Column(length: 24)]
    private string $instance = self::INSTANCE_FIRST;

    /** As printed in the listing: "60/2016", "1547/2017". */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $judgmentNumber = null;

    /** Known only after analysing the PDF. Partial-unique in the migration. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $ecli = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $roj = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $judgmentDate = null;

    /** "Asunto" of the recurso, e.g. "Coste de los canales de televisión". */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appellant = null;

    /** XLSX vocabulary: AGE | C/A/E (ciudadanos/asociaciones/empresas) | OOPP/EEPP. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appellantType = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $representation = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $outcome = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $resolutionEffect = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $transparencyStance = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /**
     * What the ruling MEANS in practice for whoever asked for the information, in one plain
     * sentence. The triad above says what happened procedurally; this says what the citizen got.
     *
     * It exists because "anulada por sentencia firme" reads like bad news and in the BOSCO case
     * it was the opposite: the Supreme Court annulled the CTBG resolution for having DENIED too
     * much, and ordered the source code handed over.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $effectiveOutcome = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keypoints = null;

    /** @var list<array{quote: string, basis: string}>|null Citable doctrine with its legal basis. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $doctrine = null;

    /** @var list<string>|null e.g. ["Ley 19/2013 art. 14.1.j"]. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $interpretedArticles = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topics = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fullText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfStoragePath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sourceUrl = null;

    /**
     * True when the sourceUrl points at CENDOJ (poderjudicial.es), which serves the search
     * shell to plain HTTP: those need the Camofox browser, a later phase.
     */
    #[ORM\Column]
    private bool $needsBrowser = false;

    #[ORM\Column]
    private bool $isFinal = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finalDate = null;

    /**
     * Canonical refs of the challenged resolutions, ALWAYS stored even when no Resolution row
     * matches yet: the CTBG corpus in the database barely covers 2015-2017, which is where
     * most litigation lives, so linking must be re-runnable after those years get imported.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $challengedResolutionRefs = null;

    /** @var array<string, mixed>|null recursoNumber, detailUrl, observations, unmatchedRefs… */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sourceMetadata = null;

    /** The judgment this one reviews: the apelación points at the instancia, the casación at the apelación. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reviewed_judgment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Judgment $reviewedJudgment = null;

    /** @var Collection<int, Resolution> */
    #[ORM\ManyToMany(targetEntity: Resolution::class, inversedBy: 'judgments')]
    #[ORM\JoinTable(name: 'judgment_resolution')]
    private Collection $resolutions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->resolutions = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Canonical citation, built from what is actually known: with ECLI when the analyzer found
     * it, bare otherwise. Never invents.
     *
     * "STS 1547/2017 (ECLI:ES:TS:2017:3626)" · "SAN 63/2016" · "SJCCA nº 9 60/2016"
     */
    public function getCitationLabel(): string
    {
        $label = match ($this->court) {
            self::COURT_TS => 'STS',
            self::COURT_AN => 'SAN',
            self::COURT_TSJ => 'STSJ',
            self::COURT_JCCA => 'SJCCA' . ($this->courtNumber !== null ? ' nº ' . $this->courtNumber : ''),
            default => 'Sentencia',
        };

        if ($this->judgmentNumber !== null) {
            $label .= ' ' . $this->judgmentNumber;
        }

        if ($this->judgmentDate !== null) {
            $label .= ', de ' . $this->judgmentDate->format('j/n/Y');
        }

        if ($this->ecli !== null) {
            $label .= ' (' . $this->ecli . ')';
        }

        return $label;
    }

    /** Per design/README.md, human labels live on the entity, not in Twig. */
    public function getInstanceLabel(): string
    {
        return match ($this->instance) {
            self::INSTANCE_FIRST => 'primera instancia',
            self::INSTANCE_APPEAL => 'apelación',
            self::INSTANCE_CASSATION => 'casación',
            default => $this->instance,
        };
    }

    public function isProAccess(): bool
    {
        return $this->transparencyStance === self::STANCE_PRO_ACCESS;
    }

    /**
     * Position in the procedural chain, for ordering a case's judgments.
     *
     * It cannot be done in SQL: sorting by `instance` alphabetically puts apelación before
     * primera instancia, and a timeline that opens with the appeal tells the story backwards.
     * Single source of truth — the repository and Resolution both sort through this.
     */
    public function instanceRank(): int
    {
        return match ($this->instance) {
            self::INSTANCE_FIRST => 0,
            self::INSTANCE_APPEAL => 1,
            self::INSTANCE_CASSATION => 2,
            default => 3,
        };
    }

    /**
     * Sorts a case's judgments the way it was litigated: instancia → apelación → casación,
     * with the date as tie-breaker.
     *
     * @param iterable<Judgment> $judgments
     *
     * @return list<Judgment>
     */
    public static function inProceduralOrder(iterable $judgments): array
    {
        $sorted = is_array($judgments) ? array_values($judgments) : iterator_to_array($judgments, false);

        usort($sorted, static fn (self $a, self $b): int => [$a->instanceRank(), $a->getJudgmentDate()?->getTimestamp() ?? 0]
            <=> [$b->instanceRank(), $b->getJudgmentDate()?->getTimestamp() ?? 0]);

        return $sorted;
    }

    public function annulsResolution(): bool
    {
        return in_array($this->resolutionEffect, [self::EFFECT_ANULA, self::EFFECT_ANULA_PARCIAL], true);
    }

    // --- accessors ---

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCourt(): string
    {
        return $this->court;
    }

    public function setCourt(string $court): static
    {
        $this->court = $court;
        return $this;
    }

    public function getCourtNumber(): ?int
    {
        return $this->courtNumber;
    }

    public function setCourtNumber(?int $courtNumber): static
    {
        $this->courtNumber = $courtNumber;
        return $this;
    }

    public function getCourtName(): ?string
    {
        return $this->courtName;
    }

    public function setCourtName(?string $courtName): static
    {
        $this->courtName = $courtName;
        return $this;
    }

    public function getChamber(): ?string
    {
        return $this->chamber;
    }

    public function setChamber(?string $chamber): static
    {
        $this->chamber = $chamber;
        return $this;
    }

    public function getInstance(): string
    {
        return $this->instance;
    }

    public function setInstance(string $instance): static
    {
        $this->instance = $instance;
        return $this;
    }

    public function getJudgmentNumber(): ?string
    {
        return $this->judgmentNumber;
    }

    public function setJudgmentNumber(?string $judgmentNumber): static
    {
        $this->judgmentNumber = $judgmentNumber;
        return $this;
    }

    public function getEcli(): ?string
    {
        return $this->ecli;
    }

    public function setEcli(?string $ecli): static
    {
        $this->ecli = $ecli;
        return $this;
    }

    public function getRoj(): ?string
    {
        return $this->roj;
    }

    public function setRoj(?string $roj): static
    {
        $this->roj = $roj;
        return $this;
    }

    public function getJudgmentDate(): ?\DateTimeImmutable
    {
        return $this->judgmentDate;
    }

    public function setJudgmentDate(?\DateTimeImmutable $judgmentDate): static
    {
        $this->judgmentDate = $judgmentDate;
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

    public function getAppellant(): ?string
    {
        return $this->appellant;
    }

    public function setAppellant(?string $appellant): static
    {
        $this->appellant = $appellant;
        return $this;
    }

    public function getAppellantType(): ?string
    {
        return $this->appellantType;
    }

    public function setAppellantType(?string $appellantType): static
    {
        $this->appellantType = $appellantType;
        return $this;
    }

    public function getRepresentation(): ?string
    {
        return $this->representation;
    }

    public function setRepresentation(?string $representation): static
    {
        $this->representation = $representation;
        return $this;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function setOutcome(?string $outcome): static
    {
        $this->outcome = $outcome;
        return $this;
    }

    public function getResolutionEffect(): ?string
    {
        return $this->resolutionEffect;
    }

    public function setResolutionEffect(?string $resolutionEffect): static
    {
        $this->resolutionEffect = $resolutionEffect;
        return $this;
    }

    public function getTransparencyStance(): ?string
    {
        return $this->transparencyStance;
    }

    public function setTransparencyStance(?string $transparencyStance): static
    {
        $this->transparencyStance = $transparencyStance;
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

    public function getEffectiveOutcome(): ?string
    {
        return $this->effectiveOutcome;
    }

    public function setEffectiveOutcome(?string $effectiveOutcome): static
    {
        $this->effectiveOutcome = $effectiveOutcome;
        return $this;
    }

    /** @return list<string> */
    public function getKeypoints(): array
    {
        return $this->keypoints ?? [];
    }

    /** @param list<string>|null $keypoints */
    public function setKeypoints(?array $keypoints): static
    {
        $this->keypoints = $keypoints;
        return $this;
    }

    /** @return list<array{quote: string, basis: string}> */
    public function getDoctrine(): array
    {
        return $this->doctrine ?? [];
    }

    /** @param list<array{quote: string, basis: string}>|null $doctrine */
    public function setDoctrine(?array $doctrine): static
    {
        $this->doctrine = $doctrine;
        return $this;
    }

    /** @return list<string> */
    public function getInterpretedArticles(): array
    {
        return $this->interpretedArticles ?? [];
    }

    /** @param list<string>|null $interpretedArticles */
    public function setInterpretedArticles(?array $interpretedArticles): static
    {
        $this->interpretedArticles = $interpretedArticles;
        return $this;
    }

    /** @return list<string> */
    public function getKeywords(): array
    {
        return $this->keywords ?? [];
    }

    /** @param list<string>|null $keywords */
    public function setKeywords(?array $keywords): static
    {
        $this->keywords = $keywords;
        return $this;
    }

    /** @return list<string> */
    public function getTopics(): array
    {
        return $this->topics ?? [];
    }

    /** @param list<string>|null $topics */
    public function setTopics(?array $topics): static
    {
        $this->topics = $topics;
        return $this;
    }

    public function getFullText(): ?string
    {
        return $this->fullText;
    }

    public function setFullText(?string $fullText): static
    {
        $this->fullText = $fullText;
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

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    public function needsBrowser(): bool
    {
        return $this->needsBrowser;
    }

    public function setNeedsBrowser(bool $needsBrowser): static
    {
        $this->needsBrowser = $needsBrowser;
        return $this;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function setIsFinal(bool $isFinal): static
    {
        $this->isFinal = $isFinal;
        return $this;
    }

    public function getFinalDate(): ?\DateTimeImmutable
    {
        return $this->finalDate;
    }

    public function setFinalDate(?\DateTimeImmutable $finalDate): static
    {
        $this->finalDate = $finalDate;
        return $this;
    }

    /** @return list<string> */
    public function getChallengedResolutionRefs(): array
    {
        return $this->challengedResolutionRefs ?? [];
    }

    /** @param list<string>|null $refs */
    public function setChallengedResolutionRefs(?array $refs): static
    {
        $this->challengedResolutionRefs = $refs;
        return $this;
    }

    /** @return array<string, mixed> */
    public function getSourceMetadata(): array
    {
        return $this->sourceMetadata ?? [];
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setSourceMetadata(?array $sourceMetadata): static
    {
        $this->sourceMetadata = $sourceMetadata;
        return $this;
    }

    public function getReviewedJudgment(): ?Judgment
    {
        return $this->reviewedJudgment;
    }

    public function setReviewedJudgment(?Judgment $reviewedJudgment): static
    {
        $this->reviewedJudgment = $reviewedJudgment;
        return $this;
    }

    /** @return Collection<int, Resolution> */
    public function getResolutions(): Collection
    {
        return $this->resolutions;
    }

    public function addResolution(Resolution $resolution): static
    {
        if (!$this->resolutions->contains($resolution)) {
            $this->resolutions->add($resolution);
        }

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

    public function __toString(): string
    {
        return $this->getCitationLabel();
    }
}
