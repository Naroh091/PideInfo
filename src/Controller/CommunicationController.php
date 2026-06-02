<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inbox-style view of the communications received through the user's virtual email.
 *
 * Inbound emails are stored as Document rows (sourceType = 'email'): one for the
 * body plus one per attachment, all sharing the same sourceMetadata.emailGroupId.
 * This controller groups them back into "one card per received email".
 */
#[Route('/comunicaciones')]
#[IsGranted('ROLE_USER')]
class CommunicationController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    #[Route('', name: 'app_comunicaciones_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $documents = $this->documentRepository->findEmailDocumentsByUser($user);

        // Group by emailGroupId preserving DESC order (documents come newest first)
        $groups = [];
        foreach ($documents as $document) {
            $metadata = $document->getSourceMetadata() ?? [];
            $groupId = $metadata['emailGroupId'] ?? ('doc-' . $document->getId());

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'from' => $metadata['from'] ?? null,
                    'subject' => $metadata['subject'] ?? '(sin asunto)',
                    'date' => $metadata['date'] ?? null,
                    'createdAt' => $document->getCreatedAt(),
                    'accessRequest' => null,
                    'documents' => [],
                ];
            }

            $groups[$groupId]['documents'][] = $document;

            // The whole email is considered linked if any of its documents is
            if ($groups[$groupId]['accessRequest'] === null && $document->getAccessRequest() !== null) {
                $groups[$groupId]['accessRequest'] = $document->getAccessRequest();
            }
        }

        // Lightweight list of the user's requests for the "link to request" selector
        $accessRequests = $this->accessRequestRepository->findByUser($user, null, 1, 200);

        return $this->render('comunicaciones/index.html.twig', [
            'groups' => array_values($groups),
            'accessRequests' => $accessRequests,
        ]);
    }
}
