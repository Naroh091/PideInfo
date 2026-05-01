<?php

namespace App\Entity;

use App\Repository\ComplaintOrganismRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ComplaintOrganismRepository::class)]
class ComplaintOrganism
{
    public const SHORT_NAME_CTBG = 'CTBG';

    /** CTBG state-scope complaint form (Reclamaciones de ámbito estatal). */
    public const CTBG_FORM_URL_STATE = 'https://sede.consejodetransparencia.gob.es/catalog/t/fd9abc4c-d3ba-4145-a2d9-ab51b0f9fa2e';

    /** CTBG autonomic/local-scope complaint form (Reclamaciones de ámbito autonómico y local). */
    public const CTBG_FORM_URL_REGIONAL = 'https://sede.consejodetransparencia.gob.es/catalog/t/2ed5dcfa-4396-485f-979a-3e39a27e971e';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shortName = null;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\ManyToOne(targetEntity: AutonomousCommunity::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AutonomousCommunity $autonomousCommunity = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complaintFormUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, ApplicableLaw> */
    #[ORM\OneToMany(targetEntity: ApplicableLaw::class, mappedBy: 'complaintOrganism')]
    private Collection $applicableLaws;

    /** @var Collection<int, Resolution> */
    #[ORM\OneToMany(targetEntity: Resolution::class, mappedBy: 'complaintOrganism')]
    private Collection $resolutions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->applicableLaws = new ArrayCollection();
        $this->resolutions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getShortName(): ?string
    {
        return $this->shortName;
    }

    public function setShortName(?string $shortName): static
    {
        $this->shortName = $shortName;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getComplaintFormUrl(): ?string
    {
        return $this->complaintFormUrl;
    }

    public function setComplaintFormUrl(?string $complaintFormUrl): static
    {
        $this->complaintFormUrl = $complaintFormUrl;
        return $this;
    }

    /**
     * Pick the right form URL for a request. Most organisms publish one form
     * regardless of scope; the CTBG is the exception — it has a state form
     * and a separate autonomic/local form, and PideInfo derives which one
     * applies from the request's `PublicBody.level`.
     */
    public function getComplaintFormUrlFor(AccessRequest $accessRequest): ?string
    {
        if ($this->shortName === self::SHORT_NAME_CTBG) {
            $level = $accessRequest->getPublicBody()?->getLevel();
            return $level === PublicBody::LEVEL_STATE
                ? self::CTBG_FORM_URL_STATE
                : self::CTBG_FORM_URL_REGIONAL;
        }
        return $this->complaintFormUrl;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
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

    /** @return Collection<int, ApplicableLaw> */
    public function getApplicableLaws(): Collection
    {
        return $this->applicableLaws;
    }

    public function addApplicableLaw(ApplicableLaw $applicableLaw): static
    {
        if (!$this->applicableLaws->contains($applicableLaw)) {
            $this->applicableLaws->add($applicableLaw);
            $applicableLaw->setComplaintOrganism($this);
        }
        return $this;
    }

    public function removeApplicableLaw(ApplicableLaw $applicableLaw): static
    {
        if ($this->applicableLaws->removeElement($applicableLaw)) {
            if ($applicableLaw->getComplaintOrganism() === $this) {
                $applicableLaw->setComplaintOrganism(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, Resolution> */
    public function getResolutions(): Collection
    {
        return $this->resolutions;
    }

    public function __toString(): string
    {
        return $this->shortName ?? $this->name;
    }
}
