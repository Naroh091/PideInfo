<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\PublicBodyRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Submission\ApplicableLawResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Matching de documentos con solicitudes y creación automática, unificado
 * para los handlers single y batch (antes cada uno llevaba su propia copia,
 * con la del batch desactualizada). La resolución de la ley aplicable sigue
 * el camino canónico del single: CCAA vía ApplicableLawResolver → nombre de
 * ley extraído por la IA → ley estatal.
 */
final class AccessRequestMatcher
{
    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly AutonomousCommunityRepository $autonomousCommunityRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Try to match one of the given reference numbers against the user's
     * requests (externalId + alternativeReferences).
     *
     * @param list<string|null> $references
     */
    public function matchByReferences(User $user, array $references): ?AccessRequest
    {
        foreach (array_filter(array_unique($references)) as $ref) {
            $existing = $this->accessRequestRepository->findByExternalId($ref, $user);
            if ($existing) {
                $this->logger->info('Matched request by reference number', [
                    'referenceNumber' => $ref,
                    'accessRequestId' => (string) $existing->getId(),
                ]);
                return $existing;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function matchByKeywords(User $user, array $analysis): ?AccessRequest
    {
        $keywords = $this->extractKeywords($analysis);
        if (empty($keywords)) {
            return null;
        }

        $existing = $this->accessRequestRepository->findByKeywords($keywords, $user);
        if ($existing) {
            $this->logger->info('Matched request by keywords', [
                'keywords' => $keywords,
                'accessRequestId' => (string) $existing->getId(),
            ]);
        }

        return $existing;
    }

    /**
     * Extract keywords from AI analysis that can be used to match related documents.
     * Looks for contract IDs, platform identifiers, and other unique references.
     *
     * @param array<string, mixed> $analysis
     * @return string[]
     */
    public function extractKeywords(array $analysis): array
    {
        $keywords = [];
        $text = ($analysis['requestDescription'] ?? '') . ' ' . ($analysis['requestTitle'] ?? '');

        // Extract contract/platform identifiers (e.g., "2020/011739", "VCM-036")
        if (preg_match_all('/\b\d{4}\/\d{5,}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract line/route codes (e.g., "VCM-036", "DIV-123")
        if (preg_match_all('/\b[A-Z]{2,4}-\d{2,4}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract expedition numbers (e.g., "AYTOZAM-SEIS-4420/2025")
        if (preg_match_all('/\b[A-Z]+-[A-Z]+-\d+\/\d{4}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract NIF/CIF references
        if (preg_match_all('/\b[A-Z]\d{7,8}[A-Z0-9]?\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        return array_unique($keywords);
    }

    /**
     * Map the AI-extracted publicBodyType to a PublicBody level. Falls back to
     * the legacy 'other' marker when the AI couldn't tell — those bodies are
     * reviewed by hand (see PublicBody docblock).
     */
    public static function deriveLevel(?string $publicBodyType): string
    {
        return match ($publicBodyType) {
            'ayuntamiento', 'diputacion' => PublicBody::LEVEL_LOCAL,
            'consejeria_autonomica', 'universidad' => PublicBody::LEVEL_AUTONOMOUS,
            'ministerio', 'organismo_autonomo' => PublicBody::LEVEL_STATE,
            default => 'other',
        };
    }

    /**
     * Create a new AccessRequest from the analysis of a Request/Receipt
     * document. Callers decide *whether* to create (type check); this method
     * owns the how: CCAA → PublicBody (find-or-create with derived level) →
     * ApplicableLaw (resolver → AI hint → state) → AccessRequestManager::create.
     *
     * @param array<string, mixed> $analysis
     */
    public function createFromAnalysis(
        User $user,
        array $analysis,
        Document $document,
        ?string $externalId,
        ?\DateTimeImmutable $sentAt = null,
    ): ?AccessRequest {
        // Find autonomous community from extracted code
        $autonomousCommunity = null;
        $ccaaCode = $analysis['autonomousCommunityCode'] ?? null;
        if ($ccaaCode) {
            $autonomousCommunity = $this->autonomousCommunityRepository->findByCode($ccaaCode);
            $this->logger->info('AI extracted autonomous community', [
                'code' => $ccaaCode,
                'found' => $autonomousCommunity !== null,
            ]);
        }

        // Find or create public body
        $publicBody = null;
        $publicBodyName = $analysis['publicBodyName'] ?? null;
        $this->logger->info('AI extracted public body', ['publicBodyName' => $publicBodyName]);
        if ($publicBodyName) {
            $publicBody = $this->publicBodyRepository->findOneByNameLike($publicBodyName);

            // Auto-create if not found
            if (!$publicBody) {
                $publicBody = new PublicBody();
                $publicBody->setName($publicBodyName);
                $publicBody->setLevel(self::deriveLevel($analysis['publicBodyType'] ?? null));
                if ($autonomousCommunity) {
                    $publicBody->setAutonomousCommunity($autonomousCommunity);
                }
                $this->entityManager->persist($publicBody);
                $this->logger->info('Created new public body from document', [
                    'name' => $publicBodyName,
                    'level' => $publicBody->getLevel(),
                    'autonomousCommunity' => $ccaaCode,
                ]);
            }
        }

        // Find applicable law — preserves the original priority:
        //   1) CCAA-derived law (resolver does findByAutonomousCommunity).
        //   2) AI-extracted law name (acts as a tiebreaker for bodies
        //      whose CCAA-derived law isn't found in the catalogue).
        //   3) State law (resolver default).
        $applicableLaw = null;
        if ($publicBody && $publicBody->getAutonomousCommunity()) {
            $applicableLaw = $this->applicableLawResolver->resolveFor($publicBody);
            if ($applicableLaw && $applicableLaw->getAutonomousCommunity()) {
                $this->logger->info('Found applicable law by autonomous community', [
                    'law' => $applicableLaw->getShortCode(),
                    'ccaa' => $ccaaCode,
                ]);
            } else {
                // Resolver fell straight to state — null it so the AI
                // hint below gets a chance.
                $applicableLaw = null;
            }
        }

        if (!$applicableLaw) {
            $lawName = $analysis['applicableLaw'] ?? null;
            if ($lawName) {
                $applicableLaw = $this->applicableLawRepository->findOneByNameLike($lawName);
            }
        }

        // Use defaults if not found
        if (!$publicBody) {
            // Get first public body as fallback (user can edit later)
            $publicBody = $this->publicBodyRepository->findOneBy([]);
        }
        if (!$applicableLaw) {
            $applicableLaw = $this->applicableLawResolver->resolveFor($publicBody);
        }

        if (!$publicBody || !$applicableLaw) {
            $this->logger->warning('Cannot create access request: missing public body or law');
            return null;
        }

        // Determine sent date
        if ($sentAt === null) {
            if (!empty($analysis['documentDate'])) {
                try {
                    $sentAt = new \DateTimeImmutable($analysis['documentDate']);
                } catch (\Exception) {
                    $sentAt = new \DateTimeImmutable();
                }
            } else {
                $sentAt = new \DateTimeImmutable();
            }
        }

        // Titles like "Solicitud de acceso a información pública" carry no
        // signal — fall back to the filename so the list stays scannable.
        $title = $analysis['requestTitle'] ?? null;
        if (!$title || in_array(mb_strtolower($title), [
            'solicitud de acceso a información pública',
        ], true)) {
            $title = 'Solicitud importada - ' . $document->getOriginalFilename();
        }

        $accessRequest = $this->accessRequestManager->create(
            user: $user,
            title: $title,
            description: $analysis['requestDescription'] ?? $analysis['summary'] ?? '',
            publicBody: $publicBody,
            applicableLaw: $applicableLaw,
            sentAt: $sentAt,
            externalId: $externalId,
        );

        $this->logger->info('Created new access request from document', [
            'accessRequestId' => (string) $accessRequest->getId(),
            'title' => $accessRequest->getTitle(),
        ]);

        return $accessRequest;
    }
}
