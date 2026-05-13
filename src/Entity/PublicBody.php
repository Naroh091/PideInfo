<?php

namespace App\Entity;

use App\Repository\PublicBodyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PublicBodyRepository::class)]
class PublicBody
{
    public const LEVEL_STATE = 'state';
    public const LEVEL_AUTONOMOUS = 'autonomous';
    public const LEVEL_LOCAL = 'local';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 50)]
    private string $level = self::LEVEL_STATE;

    #[ORM\ManyToOne(targetEntity: AutonomousCommunity::class, inversedBy: 'publicBodies')]
    #[ORM\JoinColumn(nullable: true)]
    private ?AutonomousCommunity $autonomousCommunity = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transparencyPortalUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $registryCode = null;

    /**
     * Numeric `idAmb` used by the AGE Portal de Transparencia wizard
     * (https://transparencia.sede.gob.es/procedimiento/formulario?idProc=133628&idAmb={ID}).
     * Populated for state-level public bodies that accept FOIA requests through that portal.
     */
    #[ORM\Column(nullable: true)]
    private ?int $transparencyPortalAmbId = null;

    /**
     * DIR3 code of the Raíz / Organismo principal. Populated when the body
     * was either imported from a DIR3 source or curated by a human after the
     * REG import matched it by name.
     */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $dir3Code = null;

    /**
     * True when this row was created by `app:reg:import-destinations` because
     * the DIR3 Raíz had no matching PublicBody yet. Curators in `/admin`
     * should review and enrich these (slug, address, level, etc.).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $importedFromReg = false;

    /** @var Collection<int, RegDestination> */
    #[ORM\OneToMany(targetEntity: RegDestination::class, mappedBy: 'publicBody', cascade: ['persist'])]
    private Collection $regDestinations;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->regDestinations = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getLevelLabel(): string
    {
        return match ($this->level) {
            self::LEVEL_STATE => 'Estatal',
            self::LEVEL_AUTONOMOUS => 'Autonómico',
            self::LEVEL_LOCAL => 'Local',
            default => $this->level,
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
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

    public function getTransparencyPortalUrl(): ?string
    {
        return $this->transparencyPortalUrl;
    }

    public function setTransparencyPortalUrl(?string $transparencyPortalUrl): static
    {
        $this->transparencyPortalUrl = $transparencyPortalUrl;
        return $this;
    }

    public function getRegistryCode(): ?string
    {
        return $this->registryCode;
    }

    public function setRegistryCode(?string $registryCode): static
    {
        $this->registryCode = $registryCode;
        return $this;
    }

    public function getTransparencyPortalAmbId(): ?int
    {
        return $this->transparencyPortalAmbId;
    }

    public function setTransparencyPortalAmbId(?int $transparencyPortalAmbId): static
    {
        $this->transparencyPortalAmbId = $transparencyPortalAmbId;
        return $this;
    }

    public function getDir3Code(): ?string
    {
        return $this->dir3Code;
    }

    public function setDir3Code(?string $dir3Code): static
    {
        $this->dir3Code = $dir3Code;
        return $this;
    }

    public function isImportedFromReg(): bool
    {
        return $this->importedFromReg;
    }

    public function setImportedFromReg(bool $imported): static
    {
        $this->importedFromReg = $imported;
        return $this;
    }

    /** @return Collection<int, RegDestination> */
    public function getRegDestinations(): Collection
    {
        return $this->regDestinations;
    }

    public function addRegDestination(RegDestination $destination): static
    {
        if (!$this->regDestinations->contains($destination)) {
            $this->regDestinations->add($destination);
            $destination->setPublicBody($this);
        }
        return $this;
    }

    public function removeRegDestination(RegDestination $destination): static
    {
        $this->regDestinations->removeElement($destination);
        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
