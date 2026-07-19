<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\SetQuietHours;
use App\Module\Notifications\Application\CommandHandler\SetQuietHoursHandler;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SetQuietHoursHandlerTest extends TestCase
{
    public function testStoresTheOvernightWindow(): void
    {
        $stored = NotificationPreference::defaultFor('pref-1', NotificationType::TASK_DUE);

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            function (NotificationPreference $p): bool {
                $window = $p->quietHours();

                return null !== $window
                    && '22:00' === $window->start()
                    && '07:00' === $window->end()
                    && $window->isOvernight();
            }
        ));

        (new SetQuietHoursHandler($repo))(new SetQuietHours('task_due', '22:00', '07:00'));
    }

    public function testClearsTheWindowWhenBothTimesAreAbsent(): void
    {
        $stored = NotificationPreference::defaultFor('pref-1', NotificationType::TASK_DUE);
        $stored->setQuietHours(QuietHours::fromTimes('22:00', '07:00'));

        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn($stored);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => null === $p->quietHours()
        ));

        (new SetQuietHoursHandler($repo))(new SetQuietHours('task_due'));
    }

    public function testMaterializesTheDefaultPreferenceWhenTypeWasNeverConfigured(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (NotificationPreference $p): bool => NotificationType::DAILY_DIGEST === $p->type()
                && null !== $p->quietHours()
                && '23:30' === $p->quietHours()->start()
        ));

        (new SetQuietHoursHandler($repo))(new SetQuietHours('daily_digest', '23:30', '06:15'));
    }

    public function testRejectsAHalfStatedWindowWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn(NotificationPreference::defaultFor('pref-1', NotificationType::TASK_DUE));
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new SetQuietHoursHandler($repo))(new SetQuietHours('task_due', '22:00'));
    }

    public function testRejectsAMalformedTimeWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->method('findByType')->willReturn(NotificationPreference::defaultFor('pref-1', NotificationType::TASK_DUE));
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new SetQuietHoursHandler($repo))(new SetQuietHours('task_due', '25:00', '07:00'));
    }

    public function testRejectsUnknownTypeWithoutSaving(): void
    {
        $repo = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        (new SetQuietHoursHandler($repo))(new SetQuietHours('nope', '22:00', '07:00'));
    }
}
