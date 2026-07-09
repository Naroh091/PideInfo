<?php

namespace App\MessageHandler;

use App\Message\IndexResolutionMessage;
use App\Repository\ResolutionRepository;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IndexResolutionHandler
{
    public function __construct(
        #[Autowire(service: 'fos_elastica.object_persister.resolutions')]
        private ObjectPersisterInterface $objectPersister,
        private ResolutionRepository $resolutionRepository,
    ) {
    }

    public function __invoke(IndexResolutionMessage $message): void
    {
        $resolution = $this->resolutionRepository->find($message->resolutionId);

        // The database is the sole authority: row present → upsert the document,
        // row gone → drop it. replaceOne (not insertOne) so replays are safe.
        if ($resolution === null) {
            $this->objectPersister->deleteById($message->resolutionId);

            return;
        }

        $this->objectPersister->replaceOne($resolution);
    }
}
