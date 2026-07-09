<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Resolution;
use App\Message\IndexResolutionMessage;
use App\MessageHandler\IndexResolutionHandler;
use App\Repository\ResolutionRepository;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The handler must be database-authoritative: the message only names a resolution,
 * and Postgres decides between upsert and delete. That is what makes replayed or
 * orphaned messages (a buffered id whose commit was rolled back) self-healing.
 */
final class IndexResolutionHandlerTest extends TestCase
{
    public function testUpsertsTheDocumentWhenTheRowExists(): void
    {
        $resolution = new Resolution();

        $repository = $this->createMock(ResolutionRepository::class);
        $repository->method('find')->with('11111111-1111-1111-1111-111111111111')->willReturn($resolution);

        $persister = $this->createMock(ObjectPersisterInterface::class);
        $persister->expects(self::once())->method('replaceOne')->with($resolution);
        $persister->expects(self::never())->method('deleteById');

        (new IndexResolutionHandler($persister, $repository))(
            new IndexResolutionMessage('11111111-1111-1111-1111-111111111111')
        );
    }

    public function testDropsTheDocumentWhenTheRowIsGone(): void
    {
        $repository = $this->createMock(ResolutionRepository::class);
        $repository->method('find')->willReturn(null);

        $persister = $this->createMock(ObjectPersisterInterface::class);
        $persister->expects(self::once())->method('deleteById')->with('11111111-1111-1111-1111-111111111111');
        $persister->expects(self::never())->method('replaceOne');

        (new IndexResolutionHandler($persister, $repository))(
            new IndexResolutionMessage('11111111-1111-1111-1111-111111111111')
        );
    }
}
