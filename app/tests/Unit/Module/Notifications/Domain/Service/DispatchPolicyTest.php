<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain\Service;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Service\DispatchPolicy;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DispatchPolicyTest extends TestCase
{
    private DispatchPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DispatchPolicy();
    }

    public function testAnUnconfiguredTypeGoesOutOnEveryChannel(): void
    {
        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            null,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame(Channel::cases(), $channels, 'never configured means the defaultFor state, not silence');
    }

    public function testAnUnconfiguredDailyDigestGoesNowhereUntilOptedIn(): void
    {
        // The digest is the one type that defaults off, so a never-configured
        // one resolves to no channels rather than delivering unasked.
        $channels = $this->policy->resolveChannels(
            NotificationType::DAILY_DIGEST,
            null,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame([], $channels);
    }

    public function testADisabledTypeGoesNowhere(): void
    {
        $preference = new NotificationPreference('p-1', NotificationType::TASK_DUE, false, Channel::cases());

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame([], $channels);
    }

    public function testOnlyEnabledChannelsCarryTheType(): void
    {
        $preference = new NotificationPreference('p-1', NotificationType::TASK_DUE, true, [Channel::EMAIL]);

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame([Channel::EMAIL], $channels);
    }

    public function testATypeWithNoChannelLeftGoesNowhere(): void
    {
        $preference = new NotificationPreference('p-1', NotificationType::TASK_DUE, true, []);

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame([], $channels);
    }

    public function testQuietHoursSuppressTheSendInsideTheWindow(): void
    {
        $preference = NotificationPreference::defaultFor('p-1', NotificationType::TASK_DUE);
        $preference->setQuietHours(QuietHours::fromTimes('22:00', '07:00'));

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 23:30:00'),
        );

        self::assertSame([], $channels, 'suppressed, not deferred');
    }

    public function testQuietHoursDoNotSuppressOutsideTheWindow(): void
    {
        $preference = NotificationPreference::defaultFor('p-1', NotificationType::TASK_DUE);
        $preference->setQuietHours(QuietHours::fromTimes('22:00', '07:00'));

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 07:00:00'),
        );

        self::assertSame(Channel::cases(), $channels, 'the window end is exclusive');
    }

    public function testADisabledTypeStaysSilentEvenOutsideQuietHours(): void
    {
        $preference = new NotificationPreference('p-1', NotificationType::TASK_DUE, false, Channel::cases());
        $preference->setQuietHours(QuietHours::fromTimes('22:00', '07:00'));

        $channels = $this->policy->resolveChannels(
            NotificationType::TASK_DUE,
            $preference,
            new DateTimeImmutable('2026-07-19 12:00:00'),
        );

        self::assertSame([], $channels);
    }
}
