<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * One selectable destination in the unified picker modal — either a REG unit
 * (`kind = 'reg'`, carries a regDestinationId) or a Portal/AGE body
 * (`kind = 'portal'`, no regDestinationId). The JSON keys mirror the two legacy
 * picker endpoints so the frontend row template is reusable.
 */
final readonly class DestinationCandidate
{
    public const KIND_REG = 'reg';
    public const KIND_PORTAL = 'portal';

    /**
     * @param array{id: string, name: string, shortCode: string, deadlineLabel: string}|null $applicableLaw
     */
    public function __construct(
        public string $kind,
        public string $publicBodyId,
        public ?string $regDestinationId,
        public string $name,
        public string $displayLabel,
        public ?string $dir3Code,
        public ?string $comunidad,
        public ?string $provincia,
        public string $nivelAdministracion,
        public string $nivelLabel,
        public string $channel,
        public string $channelLabel,
        public ?array $applicableLaw,
        public ?string $oficinaDir3 = null,
        public ?string $oficinaName = null,
        public ?string $raizDir3 = null,
        public ?string $raizName = null,
        public bool $semantic = false,
        public ?float $score = null,
    ) {
    }

    /**
     * The exact target shape consumed by AccessRequestController::initiateDrafts.
     *
     * @return array{publicBodyId: string, regDestinationId?: string}
     */
    public function toInitiateTarget(): array
    {
        return $this->regDestinationId === null
            ? ['publicBodyId' => $this->publicBodyId]
            : ['publicBodyId' => $this->publicBodyId, 'regDestinationId' => $this->regDestinationId];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'publicBodyId' => $this->publicBodyId,
            'regDestinationId' => $this->regDestinationId,
            'name' => $this->name,
            'displayLabel' => $this->displayLabel,
            'dir3' => $this->dir3Code,
            'comunidad' => $this->comunidad,
            'provincia' => $this->provincia,
            'nivel' => $this->nivelAdministracion,
            'nivelLabel' => $this->nivelLabel,
            'channel' => $this->channel,
            'channelLabel' => $this->channelLabel,
            'applicableLaw' => $this->applicableLaw,
            'oficinaDir3' => $this->oficinaDir3,
            'oficinaName' => $this->oficinaName,
            'raizDir3' => $this->raizDir3,
            'raizName' => $this->raizName,
            'semantic' => $this->semantic,
            'score' => $this->score,
        ];
    }
}
