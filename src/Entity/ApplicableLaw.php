<?php

namespace App\Entity;

use App\Repository\ApplicableLawRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApplicableLawRepository::class)]
class ApplicableLaw
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $shortCode;

    #[ORM\ManyToOne(targetEntity: AutonomousCommunity::class, inversedBy: 'applicableLaws')]
    #[ORM\JoinColumn(nullable: true)]
    private ?AutonomousCommunity $autonomousCommunity = null;

    public const UNIT_MONTHS = 'months';
    public const UNIT_DAYS = 'days';
    public const UNIT_BUSINESS_DAYS = 'business_days';

    #[ORM\Column]
    private int $responseDeadlineValue = 1;

    #[ORM\Column(length: 20)]
    private string $responseDeadlineUnit = self::UNIT_MONTHS;

    #[ORM\Column]
    private int $extensionValue = 1;

    #[ORM\Column(length: 20)]
    private string $extensionUnit = self::UNIT_MONTHS;

    #[ORM\Column]
    private int $maxExtensions = 1;

    #[ORM\Column]
    private int $complaintDeadlineDays = 30;

    #[ORM\Column]
    private int $complianceAfterComplaintDays = 10;

    #[ORM\Column]
    private bool $silenceIsPositive = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $officialUrl = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function setShortCode(string $shortCode): static
    {
        $this->shortCode = $shortCode;
        return $this;
    }

    public function getAutonomousCommunity(): ?AutonomousCommunity
    {
        return $this->autonomousCommunity;
    }

    public function setAutonomousCommunity(?AutonomousCommunity $autonomousCommunity): static
    {
        $this->autonomousCommunity = $autonomousCommunity;
        return $this;
    }

    public function isStateLaw(): bool
    {
        return $this->autonomousCommunity === null;
    }

    public function getResponseDeadlineValue(): int
    {
        return $this->responseDeadlineValue;
    }

    public function setResponseDeadlineValue(int $value): static
    {
        $this->responseDeadlineValue = $value;
        return $this;
    }

    public function getResponseDeadlineUnit(): string
    {
        return $this->responseDeadlineUnit;
    }

    public function setResponseDeadlineUnit(string $unit): static
    {
        $this->responseDeadlineUnit = $unit;
        return $this;
    }

    /**
     * @deprecated Use getResponseDeadlineValue() and getResponseDeadlineUnit() instead
     */
    public function getResponseDeadlineDays(): int
    {
        // For backwards compatibility, convert months to approximate days
        if ($this->responseDeadlineUnit === self::UNIT_MONTHS) {
            return $this->responseDeadlineValue * 30;
        }
        return $this->responseDeadlineValue;
    }

    /**
     * @deprecated Use setResponseDeadlineValue() and setResponseDeadlineUnit() instead
     */
    public function setResponseDeadlineDays(int $days): static
    {
        $this->responseDeadlineValue = $days;
        $this->responseDeadlineUnit = self::UNIT_DAYS;
        return $this;
    }

    public function getExtensionValue(): int
    {
        return $this->extensionValue;
    }

    public function setExtensionValue(int $value): static
    {
        $this->extensionValue = $value;
        return $this;
    }

    public function getExtensionUnit(): string
    {
        return $this->extensionUnit;
    }

    public function setExtensionUnit(string $unit): static
    {
        $this->extensionUnit = $unit;
        return $this;
    }

    /**
     * @deprecated Use getExtensionValue() and getExtensionUnit() instead
     */
    public function getExtensionDays(): int
    {
        if ($this->extensionUnit === self::UNIT_MONTHS) {
            return $this->extensionValue * 30;
        }
        return $this->extensionValue;
    }

    /**
     * @deprecated Use setExtensionValue() and setExtensionUnit() instead
     */
    public function setExtensionDays(int $days): static
    {
        $this->extensionValue = $days;
        $this->extensionUnit = self::UNIT_DAYS;
        return $this;
    }

    public function getMaxExtensions(): int
    {
        return $this->maxExtensions;
    }

    public function setMaxExtensions(int $maxExtensions): static
    {
        $this->maxExtensions = $maxExtensions;
        return $this;
    }

    public function getComplaintDeadlineDays(): int
    {
        return $this->complaintDeadlineDays;
    }

    public function setComplaintDeadlineDays(int $complaintDeadlineDays): static
    {
        $this->complaintDeadlineDays = $complaintDeadlineDays;
        return $this;
    }

    public function getComplianceAfterComplaintDays(): int
    {
        return $this->complianceAfterComplaintDays;
    }

    public function setComplianceAfterComplaintDays(int $complianceAfterComplaintDays): static
    {
        $this->complianceAfterComplaintDays = $complianceAfterComplaintDays;
        return $this;
    }

    public function isSilenceIsPositive(): bool
    {
        return $this->silenceIsPositive;
    }

    public function setSilenceIsPositive(bool $silenceIsPositive): static
    {
        $this->silenceIsPositive = $silenceIsPositive;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getOfficialUrl(): ?string
    {
        return $this->officialUrl;
    }

    public function setOfficialUrl(?string $officialUrl): static
    {
        $this->officialUrl = $officialUrl;
        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
