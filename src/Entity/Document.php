<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Document
{
    public const TYPE_REQUEST = 'request';
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_PROCESSING_START = 'processing_start';
    public const TYPE_RESPONSE = 'response';
    public const TYPE_EXTENSION = 'extension';
    public const TYPE_REDIRECTION = 'redirection';
    public const TYPE_THIRD_PARTY_RIGHTS = 'third_party_rights';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_COMPLAINT_RESOLUTION = 'complaint_resolution';
    public const TYPE_COURT = 'court';
    public const TYPE_OTHER = 'other';
    public const TYPE_UNPROCESSED = 'unprocessed';

    public const MATCH_REFERENCE = 'reference';
    public const MATCH_KEYWORDS = 'keywords';
    public const MATCH_CREATED = 'created';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 255)]
    private string $storedFilename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_UNPROCESSED;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccessRequest $accessRequest = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $uploadedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extractedText = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aiMetadata = null;

    #[ORM\Column]
    private bool $processed = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $matchMethod = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): static
    {
        $this->storedFilename = $storedFilename;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFileSizeFormatted(): string
    {
        $bytes = $this->fileSize;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
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

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_REQUEST => 'Solicitud',
            self::TYPE_RECEIPT => 'Acuse de recibo',
            self::TYPE_PROCESSING_START => 'Inicio de tramitación',
            self::TYPE_RESPONSE => 'Respuesta',
            self::TYPE_EXTENSION => 'Prórroga',
            self::TYPE_REDIRECTION => 'Traslado a otro órgano',
            self::TYPE_THIRD_PARTY_RIGHTS => 'Afectación derechos terceros',
            self::TYPE_COMPLAINT => 'Reclamación',
            self::TYPE_COMPLAINT_RESOLUTION => 'Resolución CTBG',
            self::TYPE_COURT => 'Documento judicial',
            self::TYPE_OTHER => 'Otro',
            self::TYPE_UNPROCESSED => 'Sin procesar',
            default => $this->type,
        };
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

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    public function setExtractedText(?string $extractedText): static
    {
        $this->extractedText = $extractedText;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAiMetadata(): ?array
    {
        return $this->aiMetadata;
    }

    /** @param array<string, mixed>|null $aiMetadata */
    public function setAiMetadata(?array $aiMetadata): static
    {
        $this->aiMetadata = $aiMetadata;
        return $this;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function setProcessed(bool $processed): static
    {
        $this->processed = $processed;
        if ($processed) {
            $this->processedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function setProcessingError(?string $processingError): static
    {
        $this->processingError = $processingError;
        return $this;
    }

    public function getMatchMethod(): ?string
    {
        return $this->matchMethod;
    }

    public function setMatchMethod(?string $matchMethod): static
    {
        $this->matchMethod = $matchMethod;
        return $this;
    }

    public function isKeywordMatched(): bool
    {
        return $this->matchMethod === self::MATCH_KEYWORDS;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function __toString(): string
    {
        return $this->originalFilename;
    }
}
