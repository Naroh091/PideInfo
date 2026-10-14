<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProcessedStripeEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registro de eventos de Stripe ya procesados (spec §6): la unicidad de
 * `eventId` hace el webhook idempotente — una entrega duplicada responde 200
 * sin reprocesar.
 */
#[ORM\Entity(repositoryClass: ProcessedStripeEventRepository::class)]
class ProcessedStripeEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = $eventId;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
