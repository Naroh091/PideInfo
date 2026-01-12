<?php

namespace App\Entity;

use App\Repository\AutonomousCommunityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AutonomousCommunityRepository::class)]
class AutonomousCommunity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 10, unique: true)]
    private string $code;

    /** @var Collection<int, ApplicableLaw> */
    #[ORM\OneToMany(targetEntity: ApplicableLaw::class, mappedBy: 'autonomousCommunity')]
    private Collection $applicableLaws;

    /** @var Collection<int, PublicBody> */
    #[ORM\OneToMany(targetEntity: PublicBody::class, mappedBy: 'autonomousCommunity')]
    private Collection $publicBodies;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->applicableLaws = new ArrayCollection();
        $this->publicBodies = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
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
            $applicableLaw->setAutonomousCommunity($this);
        }
        return $this;
    }

    public function removeApplicableLaw(ApplicableLaw $applicableLaw): static
    {
        if ($this->applicableLaws->removeElement($applicableLaw)) {
            if ($applicableLaw->getAutonomousCommunity() === $this) {
                $applicableLaw->setAutonomousCommunity(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, PublicBody> */
    public function getPublicBodies(): Collection
    {
        return $this->publicBodies;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
