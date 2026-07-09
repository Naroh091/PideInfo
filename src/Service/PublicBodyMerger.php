<?php

namespace App\Service;

use App\Entity\PublicBody;
use App\Message\IndexResolutionMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class PublicBodyMerger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Merge $losers into $survivor. The survivor must already carry the desired
     * field values (the caller is responsible for applying any per-field choices
     * before calling this method).
     *
     * Re-points every foreign key that points at any loser to the survivor,
     * normalizes resolution.public_body_name where it matched a loser's name,
     * then deletes the losers. Wrapped in a single transaction.
     *
     * @param list<PublicBody> $losers
     */
    public function merge(PublicBody $survivor, array $losers): MergeResult
    {
        if ($losers === []) {
            return new MergeResult(0, 0, 0, []);
        }

        $survivorId = $survivor->getId()->toRfc4122();
        $loserIds = [];
        $deletedIds = [];
        foreach ($losers as $loser) {
            $loserId = $loser->getId()->toRfc4122();
            if ($loserId === $survivorId) {
                throw new \InvalidArgumentException('Survivor cannot be in the losers list.');
            }
            $loserIds[] = $loserId;
            $deletedIds[] = $loserId;
        }

        return $this->em->wrapInTransaction(function () use ($survivor, $losers, $survivorId, $loserIds, $deletedIds): MergeResult {
            // Persist any pending field changes on the survivor before re-pointing.
            $this->em->flush();

            $arPrimary = $this->connection->executeStatement(
                'UPDATE access_request SET public_body_id = :survivor WHERE public_body_id IN (:losers)',
                ['survivor' => $survivorId, 'losers' => $loserIds],
                ['losers' => ArrayParameterType::STRING],
            );

            $arOriginal = $this->connection->executeStatement(
                'UPDATE access_request SET original_public_body_id = :survivor WHERE original_public_body_id IN (:losers)',
                ['survivor' => $survivorId, 'losers' => $loserIds],
                ['losers' => ArrayParameterType::STRING],
            );

            $resPrimary = $this->connection->executeStatement(
                'UPDATE resolution SET public_body_id = :survivor WHERE public_body_id IN (:losers)',
                ['survivor' => $survivorId, 'losers' => $loserIds],
                ['losers' => ArrayParameterType::STRING],
            );

            // Best-effort: normalize the denormalized publicBodyName on resolution rows
            // we just re-pointed, when their text matched any loser's exact name.
            $loserNames = array_map(static fn (PublicBody $l) => $l->getName(), $losers);
            $loserNames = array_values(array_unique(array_filter($loserNames, static fn ($n) => $n !== null && $n !== '')));
            if ($loserNames !== []) {
                // Native UPDATE bypasses the Doctrine listener that keeps Elasticsearch in
                // sync, and publicBodyName IS indexed — collect the ids and enqueue them
                // ourselves. The doctrine Messenger transport shares this connection, so the
                // messages join the transaction and roll back with it.
                $renamedIds = $this->connection->fetchFirstColumn(
                    'UPDATE resolution SET public_body_name = :survivorName
                     WHERE public_body_id = :survivor AND public_body_name IN (:loserNames)
                     RETURNING id',
                    [
                        'survivorName' => $survivor->getName(),
                        'survivor' => $survivorId,
                        'loserNames' => $loserNames,
                    ],
                    ['loserNames' => ArrayParameterType::STRING],
                );

                foreach ($renamedIds as $renamedId) {
                    $this->messageBus->dispatch(new IndexResolutionMessage((string) $renamedId));
                }
            }

            // Doctrine still has the loser entities loaded; remove them so any
            // associations on the ORM side are cleaned up consistently.
            foreach ($losers as $loser) {
                $this->em->remove($loser);
            }
            $this->em->flush();

            return new MergeResult(
                affectedAccessRequests: (int) $arPrimary + (int) $arOriginal,
                affectedAccessRequestsOriginal: (int) $arOriginal,
                affectedResolutions: (int) $resPrimary,
                deletedIds: $deletedIds,
            );
        });
    }

    /**
     * Count the rows that would be re-pointed when merging $losers into $survivor.
     * Used to render the preview without mutating anything.
     */
    public function previewImpact(PublicBody $survivor, array $losers): MergePreview
    {
        $loserIds = array_map(static fn (PublicBody $l) => $l->getId()->toRfc4122(), $losers);
        if ($loserIds === []) {
            return new MergePreview(0, 0, 0);
        }

        $arPrimary = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM access_request WHERE public_body_id IN (:losers)',
            ['losers' => $loserIds],
            ['losers' => ArrayParameterType::STRING],
        );
        $arOriginal = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM access_request WHERE original_public_body_id IN (:losers)',
            ['losers' => $loserIds],
            ['losers' => ArrayParameterType::STRING],
        );
        $resolutions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM resolution WHERE public_body_id IN (:losers)',
            ['losers' => $loserIds],
            ['losers' => ArrayParameterType::STRING],
        );

        return new MergePreview($arPrimary, $arOriginal, $resolutions);
    }
}
