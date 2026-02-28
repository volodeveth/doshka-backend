<?php

namespace App\Scheduler;

use App\Message\CheckDeadlinesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Symfony Scheduler: dispatches CheckDeadlinesMessage every day at 08:00 UTC.
 * The handler then fans out per-card deadline reminder emails.
 */
#[AsSchedule('main')]
class DeadlineReminderTask implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->add(
                RecurringMessage::cron('0 8 * * *', new CheckDeadlinesMessage())
            )
            ->stateful($this->cache);
    }
}
