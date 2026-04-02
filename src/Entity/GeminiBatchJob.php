<?php

namespace App\Entity;

use App\Repository\GeminiBatchJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GeminiBatchJobRepository::class)]
#[ORM\Table(name: 'gemini_batch_job')]
class GeminiBatchJob
{
    public const TASK_FORMAT = 'format';
    public const TASK_ANALYZE = 'analyze';

    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_SUCCEEDED = 'succeeded';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    /** Maps Gemini API job states to local states */
    private const GEMINI_STATE_MAP = [
        'JOB_STATE_PENDING' => self::STATE_PENDING,
        'JOB_STATE_RUNNING' => self::STATE_RUNNING,
        'JOB_STATE_SUCCEEDED' => self::STATE_SUCCEEDED,
        'JOB_STATE_FAILED' => self::STATE_FAILED,
        'JOB_STATE_CANCELLED' => self::STATE_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Gemini batch name, e.g. "batches/abc123" */
    #[ORM\Column(length: 255)]
    private string $batchName;

    /** Task type: "format" */
    #[ORM\Column(length: 50)]
    private string $task;

    /** Gemini model ID used */
    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(length: 50)]
    private string $state = self::STATE_PENDING;

    /** Number of resolutions included in this batch */
    #[ORM\Column(type: Types::INTEGER)]
    private int $resolutionCount;

    /** Source filter used when building the batch, e.g. "GAIP". Null means all sources. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sourceFilter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Number of resolutions successfully imported after completion */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $importedCount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(
        string $batchName,
        string $task,
        string $model,
        int $resolutionCount,
        ?string $sourceFilter,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->batchName = $batchName;
        $this->task = $task;
        $this->model = $model;
        $this->resolutionCount = $resolutionCount;
        $this->sourceFilter = $sourceFilter;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBatchName(): string
    {
        return $this->batchName;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function applyGeminiState(string $geminiState): static
    {
        $this->state = self::GEMINI_STATE_MAP[$geminiState] ?? $this->state;
        return $this;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_SUCCEEDED, self::STATE_FAILED, self::STATE_CANCELLED], true);
    }

    public function getResolutionCount(): int
    {
        return $this->resolutionCount;
    }

    public function getSourceFilter(): ?string
    {
        return $this->sourceFilter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(int $importedCount): static
    {
        $this->state = self::STATE_SUCCEEDED;
        $this->completedAt = new \DateTimeImmutable();
        $this->importedCount = $importedCount;
        return $this;
    }

    public function markFailed(string $errorMessage): static
    {
        $this->state = self::STATE_FAILED;
        $this->completedAt = new \DateTimeImmutable();
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getImportedCount(): ?int
    {
        return $this->importedCount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
