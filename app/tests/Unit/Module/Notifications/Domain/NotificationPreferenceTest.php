<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $quietHours = QuietHours::fromTimes('22:00', '07:00');
        $preference = new NotificationPreference(
            'p-0001',
            NotificationType::TASK_DUE,
            true,
            [Channel::EMAIL],
            $quietHours,
        );

        self::assertSame('p-0001', $preference->id());
        self::assertSame(NotificationType::TASK_DUE, $preference->type());
        self::assertTrue($preference->isEnabled());
        self::assertSame([Channel::EMAIL], $preference->enabledChannels());
        self::assertSame($quietHours, $preference->quietHours());
    }

    public function testDefaultsToAnEnabledTypeWithNoChannelsAndNoQuietHours(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::TASK_DUE);

        self::assertTrue($preference->isEnabled());
        self::assertSame([], $preference->enabledChannels());
        self::assertNull($preference->quietHours());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification preference id cannot be empty.');

        new NotificationPreference('  ', NotificationType::TASK_DUE);
    }

    public function testTogglesTheType(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::ARTICLE_DAILY);

        $preference->disable();
        self::assertFalse($preference->isEnabled());

        $preference->enable();
        self::assertTrue($preference->isEnabled());
    }

    public function testEnablesAndDisablesChannelsIndependently(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::TASK_DUE);

        $preference->enableChannel(Channel::EMAIL);
        $preference->enableChannel(Channel::PUSH);

        self::assertTrue($preference->isChannelEnabled(Channel::EMAIL));
        self::assertTrue($preference->isChannelEnabled(Channel::PUSH));

        $preference->disableChannel(Channel::PUSH);

        self::assertTrue($preference->isChannelEnabled(Channel::EMAIL));
        self::assertFalse($preference->isChannelEnabled(Channel::PUSH));
        self::assertSame([Channel::EMAIL], $preference->enabledChannels());
    }

    public function testEnablingTheSameChannelTwiceIsIdempotent(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::TASK_DUE);

        $preference->enableChannel(Channel::EMAIL);
        $preference->enableChannel(Channel::EMAIL);

        self::assertSame([Channel::EMAIL], $preference->enabledChannels());
    }

    public function testDisablingAChannelThatWasNeverEnabledIsANoOp(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::TASK_DUE, true, [Channel::EMAIL]);

        $preference->disableChannel(Channel::PUSH);

        self::assertSame([Channel::EMAIL], $preference->enabledChannels());
    }

    public function testDisablingTheTypeLeavesTheChannelsUntouched(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::TASK_DUE, true, [Channel::EMAIL]);

        $preference->disable();

        self::assertFalse($preference->isEnabled());
        self::assertSame([Channel::EMAIL], $preference->enabledChannels(), 'channels survive so re-enabling restores the setup');
    }

    public function testSetsAndClearsQuietHours(): void
    {
        $preference = new NotificationPreference('p-0001', NotificationType::GOAL_STREAK_AT_RISK);
        $quietHours = QuietHours::fromTimes('22:00', '07:00');

        $preference->setQuietHours($quietHours);
        self::assertSame($quietHours, $preference->quietHours());

        $preference->setQuietHours(null);
        self::assertNull($preference->quietHours());
    }
}
