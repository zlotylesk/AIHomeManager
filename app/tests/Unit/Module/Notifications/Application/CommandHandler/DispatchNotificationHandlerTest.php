<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Notifications\Application\CommandHandler\DispatchNotificationHandler;
use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Port\NotificationChannelInterface;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Module\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Module\Notifications\Domain\Service\DispatchPolicy;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use App\Tests\Unit\Module\Notifications\Support\RecordingChannel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DispatchNotificationHandlerTest extends TestCase
{
    /** @var list<Notification> */
    private array $saved = [];

    /** @var array<string, Notification> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->saved = [];
        $this->stored = [];
    }

    public function testDeliversOverEveryEnabledChannelAndMarksSent(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $push = new RecordingChannel(Channel::PUSH);

        $this->handler([$email, $push])(
            new DispatchNotification('task_due', 'task-42', '2026-07-16', ['title' => 'Pay rent'])
        );

        self::assertCount(1, $email->sent);
        self::assertCount(1, $push->sent);
        self::assertSame(['title' => 'Pay rent'], $email->sent[0]->payload());
        self::assertCount(2, $this->stored);

        foreach ($this->stored as $notification) {
            self::assertSame(NotificationStatus::SENT, $notification->status());
        }
    }

    public function testDoesNotAnnounceTheSameOccurrenceTwice(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $handler = $this->handler([$email], $this->emailOnlyPreference());
        $command = new DispatchNotification('task_due', 'task-42', '2026-07-16');

        $handler($command);
        $handler($command);

        self::assertCount(1, $email->sent, 'the second trigger for the same occurrence must be a no-op');
        self::assertCount(1, $this->stored);
    }

    public function testANewWindowIsAnnouncedAgain(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $handler = $this->handler([$email], $this->emailOnlyPreference());

        $handler(new DispatchNotification('task_due', 'task-42', '2026-07-16'));
        $handler(new DispatchNotification('task_due', 'task-42', '2026-07-17'));

        self::assertCount(2, $email->sent, 'the same subject in a new window is a new occurrence');
    }

    public function testADisabledTypeSendsNothingAndRecordsNothing(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $preference = new NotificationPreference('p-1', NotificationType::TASK_DUE, false, Channel::cases());

        $this->handler([$email], $preference)(new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertSame([], $email->sent);
        self::assertSame([], $this->saved);
    }

    public function testADisabledChannelIsSkippedWhileTheOtherStillDelivers(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $push = new RecordingChannel(Channel::PUSH);

        $this->handler([$email, $push], $this->emailOnlyPreference())(
            new DispatchNotification('task_due', 'task-42', '2026-07-16')
        );

        self::assertCount(1, $email->sent);
        self::assertSame([], $push->sent);
    }

    public function testQuietHoursSuppressTheWholeSend(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $preference = NotificationPreference::defaultFor('p-1', NotificationType::TASK_DUE);
        // A window spanning the whole day makes the wall clock irrelevant here;
        // the boundary behaviour is pinned in DispatchPolicyTest.
        $preference->setQuietHours(QuietHours::fromTimes('00:00', '23:59'));

        $this->handler([$email], $preference)(new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertSame([], $email->sent);
        self::assertSame([], $this->saved);
    }

    public function testAFailingChannelIsRecordedAndDoesNotStopTheOther(): void
    {
        $email = new RecordingChannel(Channel::EMAIL, failuresBeforeSuccess: 1, failureMessage: 'SMTP refused the message');
        $push = new RecordingChannel(Channel::PUSH);

        $this->handler([$email, $push])(new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertCount(1, $push->sent, 'one channel failing must not stop the other');

        $failed = $this->storedFor(Channel::EMAIL);
        self::assertSame(NotificationStatus::FAILED, $failed->status());
        self::assertSame('SMTP refused the message', $failed->failureReason());
    }

    public function testAFailedNotificationIsRetriedRatherThanDuplicated(): void
    {
        $flaky = new RecordingChannel(Channel::EMAIL, failuresBeforeSuccess: 1);
        $handler = $this->handler([$flaky], $this->emailOnlyPreference());
        $command = new DispatchNotification('task_due', 'task-42', '2026-07-16');

        $handler($command);
        $handler($command);

        self::assertSame(2, $flaky->attempts);
        self::assertCount(1, $this->stored, 'the retry reuses the existing aggregate');
        self::assertSame(NotificationStatus::SENT, $this->storedFor(Channel::EMAIL)->status());
    }

    public function testAChannelWithNoAdapterInstalledRecordsNothing(): void
    {
        $push = new RecordingChannel(Channel::PUSH);

        // Both channels are enabled by default, but only push has an adapter.
        $this->handler([$push])(new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertCount(1, $this->stored, 'no dangling PENDING row for a channel nothing can deliver');
        self::assertSame(Channel::PUSH, $this->storedFor(Channel::PUSH)->channel());
    }

    public function testRejectsAnUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->handler([])(new DispatchNotification('smoke_signal', 'task-42', '2026-07-16'));
    }

    /**
     * @param list<NotificationChannelInterface> $adapters
     */
    private function handler(array $adapters, ?NotificationPreference $preference = null): DispatchNotificationHandler
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('findByType')->willReturn($preference);

        $notifications = $this->createStub(NotificationRepositoryInterface::class);
        $notifications->method('save')->willReturnCallback(function (Notification $notification): void {
            $this->saved[] = $notification;
            $this->stored[$notification->dedupKey()] = $notification;
        });
        $notifications->method('findByDedupKey')->willReturnCallback(
            fn (string $key): ?Notification => $this->stored[$key] ?? null
        );

        return new DispatchNotificationHandler($preferences, $notifications, new DispatchPolicy(), $adapters);
    }

    private function emailOnlyPreference(): NotificationPreference
    {
        return new NotificationPreference('p-1', NotificationType::TASK_DUE, true, [Channel::EMAIL]);
    }

    private function storedFor(Channel $channel): Notification
    {
        foreach ($this->stored as $notification) {
            if ($notification->channel() === $channel) {
                return $notification;
            }
        }

        self::fail(sprintf('No notification stored for the %s channel.', $channel->value));
    }
}
