<?php

namespace App;

use App\Message\CheckCustomDeadlinesMessage;
use App\Message\UpdateExpiredRequestsMessage;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(RecurringMessage::cron('* * * * *', new CheckCustomDeadlinesMessage()))
            ->add(RecurringMessage::cron('0 7 * * *', new UpdateExpiredRequestsMessage()))
            ->add(RecurringMessage::cron('0 9 * * *', new RunCommandMessage('app:requests:notify-expiring')))
            ->add(RecurringMessage::cron('0 13 * * *', new RunCommandMessage('app:cvaip:load-resolutions --update')))
        ;
    }
}
