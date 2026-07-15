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
            ->add(RecurringMessage::cron('0 10 * * *', new RunCommandMessage('app:requests:notify-pending')))
            ->add(RecurringMessage::cron('0 0 * * *', new RunCommandMessage('app:usage-hints:hide-expired')))
            ->add(RecurringMessage::cron('30 5 * * *', new RunCommandMessage('app:anonymous-drafts:purge')))

            ->add(RecurringMessage::cron('0 1 * * *', new RunCommandMessage('app:cvaip:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 1 * * *', new RunCommandMessage('app:crt:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:ctbg:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:ctar:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:ctcyl:load-resolutions --async --update')))
            ->add(RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:ctg:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 4 * * *', new RunCommandMessage('app:ctn:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 4 * * *', new RunCommandMessage('app:ctpda:load-resolutions --async --update')))
            ->add(RecurringMessage::cron('0 5 * * *', new RunCommandMessage('app:ctpd:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 6 * * *', new RunCommandMessage('app:ctrm:load-resolutions --async')))
            ->add(RecurringMessage::cron('0 6 * * *', new RunCommandMessage('app:cvt:load-resolutions --async')))

            // legalize-es takes commits every day. One entry, not three: the command chains
            // pull → catalogue → articulado itself, under a lock.
            ->add(RecurringMessage::cron('0 8 * * *', new RunCommandMessage('app:legalize:sync')))

            // The CTBG recursos listing changes a few times a month; weekly is plenty. The
            // import is an upsert and processing is incremental (only judgments missing text,
            // analysis or vectors), so re-runs are cheap.
            ->add(RecurringMessage::cron('0 23 * * 1', new RunCommandMessage('app:judgments:load-ctbg --async')))
            ;
    }
}
