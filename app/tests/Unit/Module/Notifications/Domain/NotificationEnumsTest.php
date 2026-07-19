<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain;

use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of the Notifications enums — they are the stable
 * serialization/persistence contract the follow-up tasks rely on. The name→value
 * map is compared through its JSON encoding so the assertion stays a real
 * regression guard rather than a PHPStan-narrowed tautology.
 */
final class NotificationEnumsTest extends TestCase
{
    public function testChannelBackingValues(): void
    {
        $values = [];
        foreach (Channel::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"EMAIL":"email","PUSH":"push"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }

    public function testNotificationTypeBackingValues(): void
    {
        $values = [];
        foreach (NotificationType::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"TASK_DUE":"task_due","ARTICLE_DAILY":"article_daily","GOAL_STREAK_AT_RISK":"goal_streak_at_risk","DAILY_DIGEST":"daily_digest"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }

    public function testOnlyTheDailyDigestIsOptInByDefault(): void
    {
        self::assertTrue(NotificationType::TASK_DUE->enabledByDefault());
        self::assertTrue(NotificationType::ARTICLE_DAILY->enabledByDefault());
        self::assertTrue(NotificationType::GOAL_STREAK_AT_RISK->enabledByDefault());
        self::assertFalse(NotificationType::DAILY_DIGEST->enabledByDefault());
    }

    public function testNotificationStatusBackingValues(): void
    {
        $values = [];
        foreach (NotificationStatus::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"PENDING":"pending","SENT":"sent","FAILED":"failed"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }
}
