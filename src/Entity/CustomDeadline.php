<?php

namespace App\Entity;

use App\Repository\CustomDeadlineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CustomDeadlineRepository::class)]
#[ORM\Index(columns: ['access_request_id', 'deadline_at'], name: 'idx_custom_deadline_request_date')]
#[ORM\Index(columns: ['notified_at'], name: 'idx_custom_deadline_notified')]
#[ORM\Index(columns: ['notified_day_before_at'], name: 'idx_custom_deadline_notified_day_before')]
class CustomDeadline
{
    public const DEFAULT_NOTIFY_HOUR = 8;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class, inversedBy: 'customDeadlines')]
    #[ORM\JoinColumn(nullable: false)]
    private AccessRequest $accessRequest;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $deadlineAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifyTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedDayBeforeAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDeadlineAt(): \DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getNotifyTime(): ?\DateTimeImmutable
    {
        return $this->notifyTime;
    }

    public function setNotifyTime(?\DateTimeImmutable $notifyTime): static
    {
        $this->notifyTime = $notifyTime;
        return $this;
    }

    public function getNotifyHour(): int
    {
        return $this->notifyTime ? (int) $this->notifyTime->format('H') : self::DEFAULT_NOTIFY_HOUR;
    }

    public function getNotifiedDayBeforeAt(): ?\DateTimeImmutable
    {
        return $this->notifiedDayBeforeAt;
    }

    public function setNotifiedDayBeforeAt(?\DateTimeImmutable $notifiedDayBeforeAt): static
    {
        $this->notifiedDayBeforeAt = $notifiedDayBeforeAt;
        return $this;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function setNotifiedAt(?\DateTimeImmutable $notifiedAt): static
    {
        $this->notifiedAt = $notifiedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPassed(): bool
    {
        return $this->deadlineAt < new \DateTimeImmutable('today');
    }

    public function getDaysUntil(): int
    {
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->deadlineAt);
        return $interval->invert ? -$interval->days : $interval->days;
    }
}
