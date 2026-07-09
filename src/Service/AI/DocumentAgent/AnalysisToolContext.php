<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;

/**
 * Contexto por-ejecución de las tools del análisis agéntico de documentos.
 *
 * El análisis corre en workers de Messenger, donde NO hay token de seguridad:
 * las tools no pueden autorizarse con Security::getUser() (patrón del chat).
 * En su lugar, el analizador puebla este contexto antes de cada ejecución y
 * las tools autorizan contra él — nunca aceptan identificadores de usuario o
 * expediente procedentes del modelo.
 *
 * Es un servicio compartido: reset() es obligatorio al empezar cada análisis
 * (un worker procesa muchos documentos en el mismo proceso).
 */
final class AnalysisToolContext
{
    private ?Document $document = null;
    private ?AccessRequest $accessRequest = null;
    /** @var list<array{filename: string, type: ?string, summary: ?string}> */
    private array $batchSiblings = [];

    /**
     * @param list<array{filename: string, type: ?string, summary: ?string}> $batchSiblings
     */
    public function reset(Document $document, ?AccessRequest $accessRequest, array $batchSiblings = []): void
    {
        $this->document = $document;
        $this->accessRequest = $accessRequest;
        $this->batchSiblings = $batchSiblings;
    }

    public function getDocument(): Document
    {
        return $this->document ?? throw new \LogicException('AnalysisToolContext no inicializado: falta reset().');
    }

    public function getAccessRequest(): ?AccessRequest
    {
        $this->getDocument(); // asegura inicialización

        return $this->accessRequest;
    }

    public function getOwner(): User
    {
        return $this->getDocument()->getUploadedBy();
    }

    /**
     * @return list<array{filename: string, type: ?string, summary: ?string}>
     */
    public function getBatchSiblings(): array
    {
        return $this->batchSiblings;
    }
}
