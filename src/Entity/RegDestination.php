<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegDestinationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A DIR3 "Unidad" that the REG (Registro Electrónico Común) accepts as a
 * destination. Each row maps a leaf in the DIR3 tree to its parent Raíz
 * (modelled as a `PublicBody`). When a row exists for a `PublicBody`, that
 * body is reachable via the REG channel and the citizen will be asked to
 * pick a unit before sending.
 *
 * The intermediate Organismo (when distinct from the Raíz) is denormalised
 * because we only use it to label the picker — keeping it as plain strings
 * avoids a third entity for what is essentially display metadata.
 */
#[ORM\Entity(repositoryClass: RegDestinationRepository::class)]
#[ORM\Table(name: 'reg_destination')]
#[ORM\Index(columns: ['public_body_id'], name: 'idx_reg_destination_public_body')]
class RegDestination
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PublicBody::class, inversedBy: 'regDestinations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PublicBody $publicBody;

    #[ORM\Column(length: 12, unique: true)]
    private string $dir3Code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $intermediateOrganismDir3 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $intermediateOrganismName = null;

    /** Deepest DIR3 level (the office that physically registers the unit). */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $oficinaDir3 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oficinaName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $comunidad = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $provincia = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $nivelAdministracion = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /** Soft-disable for units that disappear from a later DIR3 export. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    public function __construct(PublicBody $publicBody, string $dir3Code, string $name)
    {
        $this->id = Uuid::v7();
        $this->publicBody = $publicBody;
        $this->dir3Code = $dir3Code;
        $this->name = $name;
    }

    public function getId(): Uuid { return $this->id; }

    public function getPublicBody(): PublicBody { return $this->publicBody; }
    public function setPublicBody(PublicBody $publicBody): static
    {
        $this->publicBody = $publicBody;
        return $this;
    }

    public function getDir3Code(): string { return $this->dir3Code; }
    public function setDir3Code(string $dir3Code): static
    {
        $this->dir3Code = $dir3Code;
        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getIntermediateOrganismDir3(): ?string { return $this->intermediateOrganismDir3; }
    public function setIntermediateOrganismDir3(?string $code): static
    {
        $this->intermediateOrganismDir3 = $code;
        return $this;
    }

    public function getIntermediateOrganismName(): ?string { return $this->intermediateOrganismName; }
    public function setIntermediateOrganismName(?string $name): static
    {
        $this->intermediateOrganismName = $name;
        return $this;
    }

    public function getOficinaDir3(): ?string { return $this->oficinaDir3; }
    public function setOficinaDir3(?string $code): static
    {
        $this->oficinaDir3 = $code;
        return $this;
    }

    public function getOficinaName(): ?string { return $this->oficinaName; }
    public function setOficinaName(?string $name): static
    {
        $this->oficinaName = $name;
        return $this;
    }

    public function getComunidad(): ?string { return $this->comunidad; }
    public function setComunidad(?string $comunidad): static
    {
        $this->comunidad = $comunidad;
        return $this;
    }

    public function getProvincia(): ?string { return $this->provincia; }
    public function setProvincia(?string $provincia): static
    {
        $this->provincia = $provincia;
        return $this;
    }

    public function getNivelAdministracion(): ?string { return $this->nivelAdministracion; }
    public function setNivelAdministracion(?string $nivel): static
    {
        $this->nivelAdministracion = $nivel;
        return $this;
    }

    public function getActivatedAt(): ?\DateTimeImmutable { return $this->activatedAt; }
    public function setActivatedAt(?\DateTimeImmutable $activatedAt): static
    {
        $this->activatedAt = $activatedAt;
        return $this;
    }

    public function getDisabledAt(): ?\DateTimeImmutable { return $this->disabledAt; }
    public function setDisabledAt(?\DateTimeImmutable $disabledAt): static
    {
        $this->disabledAt = $disabledAt;
        return $this;
    }

    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    /**
     * Used by the picker to show the chain Organismo › Unidad when the
     * intermediate differs from the Raíz.
     */
    public function getDisplayLabel(): string
    {
        if ($this->intermediateOrganismName !== null
            && $this->intermediateOrganismDir3 !== $this->publicBody->getDir3Code()
        ) {
            return $this->intermediateOrganismName . ' › ' . $this->name;
        }
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
