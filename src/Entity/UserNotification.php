<?php

namespace App\Entity;

use App\Repository\UserNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserNotificationRepository::class)]
#[ORM\Index(columns: ['user_id', 'read_at'], name: 'idx_user_notif_user_unread')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_user_notif_user_created')]
class UserNotification
{
    public const TYPE_DOCUMENT_IMPORTED = 'document_imported';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_REQUEST_CREATED = 'request_created';
    public const TYPE_NOTIFICATION_ACCEPTED = 'notification_accepted';
    public const TYPE_COMMUNICATION_ACCEPTED = 'communication_accepted';
    public const TYPE_AGENT_DOCUMENT_DOWNLOADED = 'agent_document_downloaded';
    /** Detected via async document analysis that a complaint was filed (reclamada). */
    public const TYPE_COMPLAINT_FILED = 'complaint_filed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccessRequest $accessRequest = null;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $alertLevel = 'info';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getAlertLevel(): string
    {
        return $this->alertLevel;
    }

    public function setAlertLevel(string $alertLevel): static
    {
        $this->alertLevel = $alertLevel;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markAsRead(): static
    {
        $this->readAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIcon(): string
    {
        return match ($this->type) {
            self::TYPE_DOCUMENT_IMPORTED => 'file-plus',
            self::TYPE_STATUS_CHANGED => 'arrow-right-left',
            self::TYPE_REQUEST_CREATED => 'plus-circle',
            self::TYPE_NOTIFICATION_ACCEPTED => 'bell-ring',
            self::TYPE_COMMUNICATION_ACCEPTED => 'mail-check',
            self::TYPE_AGENT_DOCUMENT_DOWNLOADED => 'download',
            self::TYPE_COMPLAINT_FILED => 'gavel',
            default => 'info',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DOCUMENT_IMPORTED => 'Documento importado',
            self::TYPE_STATUS_CHANGED => 'Cambio de estado',
            self::TYPE_REQUEST_CREATED => 'Solicitud creada',
            self::TYPE_NOTIFICATION_ACCEPTED => 'Notificación aceptada',
            self::TYPE_COMMUNICATION_ACCEPTED => 'Comunicación aceptada',
            self::TYPE_AGENT_DOCUMENT_DOWNLOADED => 'Documentación importada',
            self::TYPE_COMPLAINT_FILED => 'Reclamación presentada',
            default => 'Notificación',
        };
    }

    public function getFromStatus(): ?string
    {
        return $this->metadata['fromStatus'] ?? null;
    }

    public function getToStatus(): ?string
    {
        return $this->metadata['toStatus'] ?? null;
    }

    public static function translateStatus(string $status): string
    {
        return match ($status) {
            // Posiciones vivas (vocabulario del rediseño).
            'pending' => 'Borrador',
            'sent' => 'Enviada',
            'processing' => 'En trámite',
            'granted' => 'Pendiente de recepción',
            'finished' => 'Finalizada',
            'delayed' => 'Silencio',
            // Posiciones deprecated — notificaciones históricas.
            'granted_completed' => 'Completada',
            'denied' => 'Denegada',
            'none' => 'Sin reclamación',
            'reclaimed' => 'Reclamada',
            'complaint_granted' => 'Estimada',
            'complaint_denied' => 'Desestimada',
            'complaint_archived' => 'Archivada',
            'in_court' => 'En tribunal',
            'court_granted' => 'Favorable',
            'court_denied' => 'Desfavorable',
            default => $status,
        };
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'sent' => 'status-sent',
            'processing' => 'status-processing',
            'granted', 'complaint_granted', 'court_granted' => 'status-granted',
            'finished', 'granted_completed' => 'status-granted-completed',
            'denied', 'complaint_denied', 'court_denied' => 'status-denied',
            'delayed' => 'status-delayed',
            'pending' => 'status-pending',
            'reclaimed', 'in_court' => 'status-processing',
            default => 'badge-secondary',
        };
    }
}
