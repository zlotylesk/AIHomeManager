<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain\Event;

use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\Event\TaskUpdated;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TaskAnnouncementTest extends TestCase
{
    public function testATaskScheduledForTodayIsWorthAnnouncing(): void
    {
        $event = new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('today 18:00'));

        $request = $event->toNotificationRequest();

        self::assertNotNull($request);
        self::assertSame('task_due', $request->type);
        self::assertSame('task-42', $request->subject);
        self::assertSame(new DateTimeImmutable('today')->format('Y-m-d'), $request->window);
        self::assertSame('Zapłacić czynsz', $request->payload['title']);
    }

    /**
     * A task booked for next week is not news yet — the scheduler sweep picks its
     * deadline up when the day actually comes.
     */
    public function testATaskScheduledForAnotherDayIsNotAnnounced(): void
    {
        $event = new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('+7 days 18:00'));

        self::assertNull($event->toNotificationRequest());
    }

    public function testEditingATaskIsAnnouncedUnderTheSameOccurrence(): void
    {
        $created = new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('today 18:00'));
        $updated = new TaskUpdated('42', new TaskTitle('Zapłacić czynsz dziś'), $this->slotAt('today 19:00'));

        $first = $created->toNotificationRequest();
        $second = $updated->toNotificationRequest();

        self::assertNotNull($first);
        self::assertNotNull($second);
        // Same subject + window = same occurrence, so the dedup key collapses the
        // two into one announcement however often the task is edited today.
        self::assertSame($first->subject, $second->subject);
        self::assertSame($first->window, $second->window);
    }

    private function slotAt(string $start): TimeSlot
    {
        $startsAt = new DateTimeImmutable($start);

        return new TimeSlot($startsAt, $startsAt->modify('+1 hour'));
    }
}
