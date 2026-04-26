<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\DocumentContent;
use App\Mcp\Service\DocumentContentReader;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Read all documents attached to a single access request in one call. Avoids the
 * round-trip cost of pairing list_user_documents with N read_document calls.
 */
#[McpTool(
    name: 'read_request_documents',
    description: 'Lee el contenido de todos los documentos de una solicitud propia en una sola llamada. mode=text devuelve texto; mode=blob devuelve base64 (limitado a 5 docs).',
)]
final class ReadRequestDocumentsTool
{
    private const BLOB_HARD_LIMIT = 5;

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly DocumentContentReader $reader,
    ) {
    }

    /**
     * @param string $requestId UUID of the access request.
     * @param string $mode      'text' (default) or 'blob'.
     * @param int    $limit     Max documents to return (1-50, capped to 5 when mode=blob).
     *
     * @return array{requestId: string, documents: list<DocumentContent>, count: int, total: int, truncated: bool, mode: string}
     */
    public function __invoke(string $requestId, string $mode = 'text', int $limit = 20): array
    {
        $this->tokenContext->requireScope('mcp:documents');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        if (!in_array($mode, ['text', 'blob'], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'text' or 'blob'.");
        }

        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $cap = $mode === 'blob' ? self::BLOB_HARD_LIMIT : 50;
        $limit = max(1, min($cap, $limit));

        $allDocs = $request->getDocuments()->toArray();
        $total = count($allDocs);
        $slice = array_slice($allDocs, 0, $limit);

        $contents = array_map(fn ($doc) => $this->reader->read($doc, $mode), $slice);

        return [
            'requestId' => $request->getId()->toRfc4122(),
            'documents' => $contents,
            'count' => count($contents),
            'total' => $total,
            'truncated' => $total > $limit,
            'mode' => $mode,
        ];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
