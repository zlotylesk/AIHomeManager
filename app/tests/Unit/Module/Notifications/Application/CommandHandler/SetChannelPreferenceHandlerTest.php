<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\SetChannelPreference;
use App\Module\Notifications\Application\CommandHandler\SetChannelPreferenceHandler;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SetChannelPreferenceHandlerTest extends TestCase
{
    public function testDisablesChannelOnTheStoredPreference(): void
    {
        $stored = NotificationPreference::defaultFor('pref-1', NotificationType::TASK_DUE);

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => !$p->isChannelEnabled(Channel::EMAIL)
                && $p->isChannelEnabled(Channel::PUSH)
        ));

        (new SetChannelPreferenceHandler($repo))(new SetChannelPreference('task_due', 'email', false));
    }

    public function testEnablesChannelOnTheStoredPreference(): void
    {
        $stored = new NotificationPreference('pref-1', NotificationType::TASK_DUE, true, []);

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => $p->isChannelEnabled(Channel::PUSH)
        ));

        (new SetChannelPreferenceHandler($repo))(new SetChannelPreference('task_due', 'push', true));
    }

    public function testMaterializesTheDefaultPreferenceWhenTypeWasNeverConfigured(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => NotificationType::DAILY_DIGEST === $p->type()
                && $p->isEnabled()
                && !$p->isChannelEnabled(Channel::PUSH)
                && $p->isChannelEnabled(Channel::EMAIL)
                && null === $p->quietHours()
        ));

        (new SetChannelPreferenceHandler($repo))(new SetChannelPreference('daily_digest', 'push', false));
    }

    public function testRejectsUnknownTypeWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new SetChannelPreferenceHandler($repo))(new SetChannelPreference('smoke_signal', 'email', true));
    }

    public function testRejectsUnknownChannelWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new SetChannelPreferenceHandler($repo))(new SetChannelPreference('task_due', 'carrier_pigeon', true));
    }
}
