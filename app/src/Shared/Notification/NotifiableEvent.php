<?php

declare(strict_types=1);

namespace App\Shared\Notification;

/**
 * Marks a domain event that may warrant a proactive notification.
 *
 * This is the reactive trigger's whole coupling story: a source module opts one
 * of its events in by implementing this, and Notifications listens for the
 * *interface* — never for `Tasks\Domain\Event\TaskCreated` or any other module's
 * class. Both sides point at the shared kernel, so deptrac stays at zero
 * violations in both directions.
 *
 * The decision of whether an occurrence is worth announcing belongs to the event
 * itself, because only the source module understands its own data (whether a
 * task's slot falls today, say). Notifications then applies the user's
 * preferences and quiet hours on top.
 */
interface NotifiableEvent
{
    /**
     * The announcement this event warrants, or null when it warrants none —
     * most events of a notifiable class are ordinary and pass silently.
     */
    public function toNotificationRequest(): ?NotificationRequest;
}
