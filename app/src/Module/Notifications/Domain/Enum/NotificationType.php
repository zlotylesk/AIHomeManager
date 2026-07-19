<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Enum;

/**
 * What a notification is about. Each type is independently opt-in/out per
 * channel — see {@see \App\Module\Notifications\Domain\Entity\NotificationPreference}.
 * Backed values are the stable serialization/persistence contract.
 */
enum NotificationType: string
{
    case TASK_DUE = 'task_due';
    case ARTICLE_DAILY = 'article_daily';
    case GOAL_STREAK_AT_RISK = 'goal_streak_at_risk';
    case DAILY_DIGEST = 'daily_digest';

    /**
     * Whether the type is on the moment the user first sees it, before
     * configuring anything. Everything defaults on except the daily digest:
     * with every type enabled the digest would duplicate the individual
     * reminders it summarises, so it is opt-in — the user turns it on when they
     * want the summary instead of (or as well as) the per-item notifications.
     */
    public function enabledByDefault(): bool
    {
        return self::DAILY_DIGEST !== $this;
    }
}
