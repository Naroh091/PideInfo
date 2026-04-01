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
