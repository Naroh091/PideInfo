<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AgentTaskRepository::class)]
#[ORM\Table(name: 'agent_task')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_agent_task_user_status')]
class AgentTask
{
    public const TYPE_PRESENT_COMPLAINT = 'present_complaint';
    public const TYPE_PRESENT_COMPLAINT_REG = 'present_complaint_reg';
    public const TYPE_SUBMIT_REQUEST_PORTAL = 'submit_request_transparencia';
    public const TYPE_SUBMIT_REQUEST_REG = 'submit_request_reg';

    public const MODE_AUTO = 'auto';
    public const MODE_SUPERVISED = 'supervised';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    /**
     * El agente perdió visibilidad después de iniciar la firma: no se puede
     * descartar que la presentación llegara al portal. No es re-enviable a
     * ciegas — ver SubmissionGuard.
     */
    public const STATUS_UNCERTAIN = 'uncertain';

    public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_UNCERTAIN];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccessRequest $accessRequest = null;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    public function __construct(User $user, string $type)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getAccessRequest(): ?AccessRequest { return $this->accessRequest; }
    public function setAccessRequest(?AccessRequest $r): self { $this->accessRequest = $r; return $this; }
    public function getType(): string { return $this->type; }
    public function getMode(): ?string { return $this->mode; }
    public function setMode(?string $m): self { $this->mode = $m; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $p): self { $this->payload = $p; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $d): self { $this->claimedAt = $d; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $d): self { $this->completedAt = $d; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $m): self { $this->errorMessage = $m; return $this; }
    public function getResult(): ?array { return $this->result; }
    public function setResult(?array $r): self { $this->result = $r; return $this; }

    public function isTerminal(): bool { return in_array($this->status, self::TERMINAL_STATUSES, true); }
}
