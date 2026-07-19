<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Notifications\Application\Command\SetChannelPreference;
use App\Module\Notifications\Application\Command\SetQuietHours;
use App\Module\Notifications\Application\Command\ToggleNotificationType;
use App\Module\Notifications\Application\CommandHandler\DispatchNotificationHandler;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Module\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Module\Notifications\Domain\Service\DispatchPolicy;
use App\Tests\Unit\Module\Notifications\Support\RecordingChannel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drives the real dispatch engine against real persistence, stubbing only the
 * channel adapters — so the rules that decide "send, skip, or skip because it
 * was already announced" are proven end to end rather than per unit.
 *
 * Preferences are written through the real command bus, which means the read the
 * engine performs is the one the settings screen actually produces.
 */
final class DispatchQuietHoursDedupTest extends KernelTestCase
{
    private MessageBusInterface $commandBus;
    private Connection $connection;
    private NotificationRepositoryInterface $notifications;
    private NotificationPreferenceRepositoryInterface $preferences;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->commandBus = $container->get('command.bus');
        $this->connection = $container->get(Connection::class);
        $this->notifications = $container->get(NotificationRepositoryInterface::class);
        $this->preferences = $container->get(NotificationPreferenceRepositoryInterface::class);

        $this->connection->executeStatement('DELETE FROM notifications');
        $this->connection->executeStatement('DELETE FROM notification_preferences');
    }

    public function testAnAnnouncementReachesEveryEnabledChannelAndIsRecorded(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $push = new RecordingChannel(Channel::PUSH);

        $this->dispatch([$email, $push], new DispatchNotification('task_due', 'task-42', '2026-07-16', ['title' => 'Czynsz']));

        self::assertCount(1, $email->sent);
        self::assertCount(1, $push->sent);
        self::assertSame(2, $this->storedCount());
        self::assertSame(NotificationStatus::SENT, $this->storedFor('task_due:task-42:2026-07-16:email')->status());
    }

    public function testATypeTurnedOffInTheSettingsSendsNothing(): void
    {
        $this->commandBus->dispatch(new ToggleNotificationType('task_due', false));

        $email = new RecordingChannel(Channel::EMAIL);
        $this->dispatch([$email], new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertSame([], $email->sent);
        self::assertSame(0, $this->storedCount(), 'a suppressed type must leave no record to retry');
    }

    public function testAChannelTurnedOffIsSkippedWhileTheOtherStillDelivers(): void
    {
        $this->commandBus->dispatch(new SetChannelPreference('task_due', 'push', false));

        $email = new RecordingChannel(Channel::EMAIL);
        $push = new RecordingChannel(Channel::PUSH);
        $this->dispatch([$email, $push], new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertCount(1, $email->sent);
        self::assertSame([], $push->sent);
        self::assertSame(1, $this->storedCount());
    }

    /**
     * Quiet hours suppress rather than defer — a reminder held until morning
     * would announce a deadline that may already have passed.
     */
    public function testQuietHoursSuppressTheWholeAnnouncement(): void
    {
        $this->commandBus->dispatch(new SetQuietHours('task_due', '00:00', '23:59'));

        $email = new RecordingChannel(Channel::EMAIL);
        $this->dispatch([$email], new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertSame([], $email->sent);
        self::assertSame(0, $this->storedCount());
    }

    public function testClearingQuietHoursLetsTheAnnouncementThroughAgain(): void
    {
        $this->commandBus->dispatch(new SetQuietHours('task_due', '00:00', '23:59'));
        $this->commandBus->dispatch(new SetQuietHours('task_due'));

        $email = new RecordingChannel(Channel::EMAIL);
        $this->dispatch([$email], new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        self::assertCount(1, $email->sent);
    }

    /**
     * The rule both triggers depend on: one occurrence, announced once, however
     * many times it is reported.
     */
    public function testTheSameOccurrenceIsAnnouncedOnlyOnce(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);
        $command = new DispatchNotification('task_due', 'task-42', '2026-07-16');

        $this->dispatch([$email], $command);
        $this->dispatch([$email], $command);

        self::assertCount(1, $email->sent);
        self::assertSame(1, $this->storedCount());
    }

    public function testANewWindowIsANewOccurrence(): void
    {
        $email = new RecordingChannel(Channel::EMAIL);

        $this->dispatch([$email], new DispatchNotification('task_due', 'task-42', '2026-07-16'));
        $this->dispatch([$email], new DispatchNotification('task_due', 'task-42', '2026-07-17'));

        self::assertCount(2, $email->sent, 'the same task tomorrow is a new occurrence');
        self::assertSame(2, $this->storedCount());
    }

    /**
     * A transient outage must not silently lose the notification: the failed
     * record is retried on its existing aggregate rather than duplicated.
     */
    public function testAFailedDeliveryIsRetriedOnTheNextTrigger(): void
    {
        $flaky = new RecordingChannel(Channel::EMAIL, failuresBeforeSuccess: 1, failureMessage: 'SMTP refused');
        $command = new DispatchNotification('task_due', 'task-42', '2026-07-16');

        $this->dispatch([$flaky], $command);
        self::assertSame(NotificationStatus::FAILED, $this->storedFor('task_due:task-42:2026-07-16:email')->status());
        self::assertSame('SMTP refused', $this->storedFor('task_due:task-42:2026-07-16:email')->failureReason());

        $this->dispatch([$flaky], $command);

        self::assertSame(2, $flaky->attempts);
        self::assertSame(1, $this->storedCount(), 'the retry reuses the existing record');
        self::assertSame(NotificationStatus::SENT, $this->storedFor('task_due:task-42:2026-07-16:email')->status());
    }

    /**
     * @param list<RecordingChannel> $channels
     */
    private function dispatch(array $channels, DispatchNotification $command): void
    {
        // The engine is built by hand so the channel adapters can be stubbed;
        // everything else — policy, preferences, persistence — is the real thing.
        $handler = new DispatchNotificationHandler(
            $this->preferences,
            $this->notifications,
            new DispatchPolicy(),
            $channels,
        );

        $handler($command);
    }

    private function storedCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notifications');
    }

    private function storedFor(string $dedupKey): \App\Module\Notifications\Domain\Entity\Notification
    {
        $notification = $this->notifications->findByDedupKey($dedupKey);
        self::assertNotNull($notification, sprintf('No notification stored for "%s".', $dedupKey));

        return $notification;
    }
}
