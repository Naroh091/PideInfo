<?php

namespace App\Entity;

use App\Repository\ReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReminderRepository::class)]
#[ORM\Table(name: 'reminder')]
#[ORM\Index(columns: ['user_id', 'remind_at'], name: 'idx_reminder_user_date')]
#[ORM\Index(columns: ['access_request_id', 'remind_at'], name: 'idx_reminder_request_date')]
class Reminder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AccessRequest $accessRequest = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $remindAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dismissedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getAccessRequest(): ?AccessRequest
    {
        return $this->accessRequest;
    }

    public function setAccessRequest(?AccessRequest $accessRequest): static
    {
        $this->accessRequest = $accessRequest;
        return $this;
    }

    public function getRemindAt(): \DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function setRemindAt(\DateTimeImmutable $remindAt): static
    {
        $this->remindAt = $remindAt;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDismissedAt(): ?\DateTimeImmutable
    {
        return $this->dismissedAt;
    }

    public function dismiss(): static
    {
        $this->dismissedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPending(): bool
    {
        return $this->dismissedAt === null;
    }
}
