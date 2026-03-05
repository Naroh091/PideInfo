<?php

namespace App\Entity;

use App\Repository\AccessRequestListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessRequestListRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['user_id'], name: 'idx_list_user')]
#[ORM\Index(columns: ['organization_id', 'visibility'], name: 'idx_list_org_visibility')]
class AccessRequestList
{
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SHARED = 'shared';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $visibility = self::VISIBILITY_PRIVATE;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Organization $organization = null;

    /** @var Collection<int, AccessRequestListItem> */
    #[ORM\OneToMany(targetEntity: AccessRequestListItem::class, mappedBy: 'list', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function getVisibilityLabel(): string
    {
        return match ($this->visibility) {
            self::VISIBILITY_PRIVATE => 'Privada',
            self::VISIBILITY_SHARED => 'Compartida',
            default => $this->visibility,
        };
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    /** @return Collection<int, AccessRequestListItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(AccessRequestListItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setList($this);
        }
        return $this;
    }

    public function removeItem(AccessRequestListItem $item): static
    {
        $this->items->removeElement($item);
        return $this;
    }

    public function getItemCount(): int
    {
        return $this->items->count();
    }

    public function hasAccessRequest(AccessRequest $accessRequest): bool
    {
        foreach ($this->items as $item) {
            if ($item->getAccessRequest()->getId()->equals($accessRequest->getId())) {
                return true;
            }
        }
        return false;
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
        return $this->name;
    }
}
