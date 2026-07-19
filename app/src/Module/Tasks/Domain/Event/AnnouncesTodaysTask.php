<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use App\Shared\Notification\NotificationRequest;

/**
 * Shared announcement rule for the task events that carry a schedule, i.e. those
 * exposing $taskId, $title, $timeSlot and $occurredAt.
 *
 * Only a task landing on *today's* schedule is worth announcing reactively — one
 * booked for next week is not news yet, and the scheduler sweep picks its
 * deadline up when the day comes. Deciding this here rather than inside the
 * Notifications module is deliberate: what a TimeSlot means is Tasks' knowledge,
 * and the shared kernel deliberately carries no domain rules.
 *
 * The window is the scheduled date, so editing the same task repeatedly during
 * the day still announces it once.
 */
trait AnnouncesTodaysTask
{
    public function toNotificationRequest(): ?NotificationRequest
    {
        $startsAt = $this->timeSlot->startDateTime();

        if ($startsAt->format('Y-m-d') !== $this->occurredAt->format('Y-m-d')) {
            return null;
        }

        return new NotificationRequest(
            type: 'task_due',
            subject: 'task-'.$this->taskId,
            window: $startsAt->format('Y-m-d'),
            payload: [
                'title' => $this->title->value(),
                'dueAt' => $startsAt->format('Y-m-d H:i'),
            ],
        );
    }
}
