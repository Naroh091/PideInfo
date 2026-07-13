<?php

namespace App\EventListener;

use App\Entity\Resolution;
use App\Message\IndexResolutionMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the Elasticsearch index in sync with the `resolution` table.
 *
 * The FOSElasticaBundle Doctrine listener is disabled (see config/packages/fos_elastica.yaml):
 * indexing must never happen inside the request or the importer that wrote the entity.
 * Instead we buffer the affected ids and dispatch one IndexResolutionMessage per resolution
 * on postFlush, once the transaction has been committed. Messages carry only the id — the
 * handler re-reads Postgres and upserts or deletes accordingly, so an id buffered during a
 * commit that later failed produces a harmless no-op, not a destructive replay.
 *
 * Blind spot by design: writes that bypass the UnitOfWork (DQL UPDATE, native SQL) never
 * reach this listener. Callers doing that must enqueue the ids themselves (see
 * PublicBodyMerger) or trigger a `fos:elastica:populate`.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class ResolutionIndexListener
{
    /**
     * Entity fields mirrored into the index. An update touching none of them
     * (e.g. only `updatedAt`) does not need to be reindexed.
     *
     * @var list<string>
     */
    private const INDEXED_FIELDS = [
        'referenceNumber',
        'subject',
        'summary',
        'claimReason',
        'fullText',
        'keywords',
        'limits',
        'inadmissionCauses',
        'topics',
        'outcome',
        'judicialStatus',
        'source',
        'scope',
        'entityType',
        'publicBodyName',
        'complaintOrganism',
        'resolutionDate',
        'claimDate',
        'infoRequestDate',
        'entryYear',
    ];

    /** @var array<string, true> resolution ids awaiting a post-commit dispatch */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->schedule($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $resolution = $args->getObject();
        if (!$resolution instanceof Resolution) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($resolution);
        if (array_intersect(self::INDEXED_FIELDS, array_keys($changeSet)) === []) {
            return;
        }

        $this->schedule($resolution);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->schedule($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        // Reset first: dispatching may itself trigger a flush, and we must not re-enter.
        $pending = array_keys($this->pending);
        $this->pending = [];

        foreach ($pending as $i => $id) {
            try {
                $this->messageBus->dispatch(new IndexResolutionMessage($id));
            } catch (\Throwable $e) {
                // The rows are committed; only their index refresh is lost. Log every
                // undispatched id so the drift can be repaired without a full repopulate.
                $this->logger->error('Failed to enqueue resolution reindex; search index is stale for these ids', [
                    'ids' => array_slice($pending, $i),
                    'exception' => $e,
                ]);

                throw $e;
            }
        }
    }

    /**
     * A successful flush always drains the buffer in postFlush, so a non-empty buffer
     * here means the commit failed or was rolled back (e.g. a Messenger worker clearing
     * the EntityManager while recovering from an error). Dropping the ids is safe: the
     * handler is DB-authoritative anyway, so even a lost cleanup only costs a no-op.
     */
    public function onClear(OnClearEventArgs $args): void
    {
        $this->pending = [];
    }

    private function schedule(object $entity): void
    {
        if (!$entity instanceof Resolution) {
            return;
        }

        $this->pending[(string) $entity->getId()] = true;
    }
}
