<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\ToggleNotificationType;
use App\Module\Notifications\Application\CommandHandler\ToggleNotificationTypeHandler;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ToggleNotificationTypeHandlerTest extends TestCase
{
    public function testOptsOutOfTheType(): void
    {
        $stored = NotificationPreference::defaultFor('pref-1', NotificationType::ARTICLE_DAILY);

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => !$p->isEnabled()
        ));

        (new ToggleNotificationTypeHandler($repo))(new ToggleNotificationType('article_daily', false));
    }

    public function testOptsBackIntoTheTypeWithoutTouchingChannels(): void
    {
        $stored = new NotificationPreference('pref-1', NotificationType::ARTICLE_DAILY, false, [Channel::EMAIL]);

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => $p->isEnabled()
                && $p->isChannelEnabled(Channel::EMAIL)
                && !$p->isChannelEnabled(Channel::PUSH)
        ));

        (new ToggleNotificationTypeHandler($repo))(new ToggleNotificationType('article_daily', true));
    }

    public function testMaterializesTheDefaultPreferenceWhenTypeWasNeverConfigured(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => NotificationType::GOAL_STREAK_AT_RISK === $p->type()
                && !$p->isEnabled()
        ));

        (new ToggleNotificationTypeHandler($repo))(new ToggleNotificationType('goal_streak_at_risk', false));
    }

    public function testRejectsUnknownTypeWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new ToggleNotificationTypeHandler($repo))(new ToggleNotificationType('nope', true));
    }
}
