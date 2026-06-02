<?php

namespace App\Entity;

use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Document
{
    public const MATCH_REFERENCE = 'reference';
    public const MATCH_KEYWORDS = 'keywords';
    public const MATCH_CREATED = 'created';

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_EMAIL = 'email';
    public const SOURCE_PORTAL = 'portal';

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

    #[ORM\Column(length: 50, enumType: DocumentType::class)]
    private DocumentType $type = DocumentType::Unprocessed;

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

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sourceType = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sourceMetadata = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $documentDate = null;

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

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function setType(DocumentType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTypeLabel(): string
    {
        return $this->type->label();
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

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): static
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    public function isFromEmail(): bool
    {
        return $this->sourceType === self::SOURCE_EMAIL;
    }

    /** @return array<string, mixed>|null */
    public function getSourceMetadata(): ?array
    {
        return $this->sourceMetadata;
    }

    /** @param array<string, mixed>|null $sourceMetadata */
    public function setSourceMetadata(?array $sourceMetadata): static
    {
        $this->sourceMetadata = $sourceMetadata;
        return $this;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): static
    {
        $this->contentHash = $contentHash;
        return $this;
    }

    public function isFromPortal(): bool
    {
        return $this->sourceType === self::SOURCE_PORTAL;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getDocumentDate(): ?\DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function setDocumentDate(?\DateTimeImmutable $documentDate): static
    {
        $this->documentDate = $documentDate;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->aiMetadata['summary'] ?? null;
    }

    /**
     * Returns a display filename in the format: "<TypeLabel> - <original_name>.<ext>"
     */
    public function getDisplayFilename(): string
    {
        if ($this->type === DocumentType::Unprocessed || $this->type === DocumentType::Other) {
            return $this->originalFilename;
        }

        // Strip any previous type-label prefix so a reclassification (e.g. a
        // reprocessed Alegaciones doc refined to Audiencia) replaces the prefix
        // instead of stacking "Tipo nuevo - Tipo viejo - nombre.pdf".
        $name = $this->originalFilename;
        foreach (DocumentType::cases() as $case) {
            $stalePrefix = $case->label() . ' - ';
            if (str_starts_with($name, $stalePrefix)) {
                $name = substr($name, strlen($stalePrefix));
                break;
            }
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $typeLabel = $this->type->label();

        // Avoid duplication if the original already starts with the type label
        if (str_starts_with($name, $typeLabel)) {
            return $name;
        }

        return $extension
            ? sprintf('%s - %s.%s', $typeLabel, $baseName, $extension)
            : sprintf('%s - %s', $typeLabel, $baseName);
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
