<?php

namespace App\Entity;

use App\Repository\AccessRequestListItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessRequestListItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_list_request', columns: ['list_id', 'access_request_id'])]
class AccessRequestListItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequestList::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequestList $list;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class, inversedBy: 'listItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequest $accessRequest;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getList(): AccessRequestList
    {
        return $this->list;
    }

    public function setList(AccessRequestList $list): static
    {
        $this->list = $list;
        return $this;
    }

    public function getAccessRequest(): AccessRequest
    {
        return $this->accessRequest;
    }

    public function setAccessRequest(AccessRequest $accessRequest): static
    {
        $this->accessRequest = $accessRequest;
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

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
