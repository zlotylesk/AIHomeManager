<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-15 10:00:00');
        $notification = new Notification(
            'n-0001',
            NotificationType::TASK_DUE,
            Channel::EMAIL,
            ['taskId' => 42, 'title' => 'Pay the bills'],
            'task_due:42:2026-07-16',
            $createdAt,
        );

        self::assertSame('n-0001', $notification->id());
        self::assertSame(NotificationType::TASK_DUE, $notification->type());
        self::assertSame(Channel::EMAIL, $notification->channel());
        self::assertSame(['taskId' => 42, 'title' => 'Pay the bills'], $notification->payload());
        self::assertSame('task_due:42:2026-07-16', $notification->dedupKey());
        self::assertSame($createdAt, $notification->createdAt());
    }

    public function testStartsPendingAndUndelivered(): void
    {
        $notification = self::notification();

        self::assertSame(NotificationStatus::PENDING, $notification->status());
        self::assertNull($notification->sentAt());
        self::assertNull($notification->failureReason());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification id cannot be empty.');

        self::notification(id: '   ');
    }

    public function testThrowsWhenDedupKeyIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification dedup key cannot be empty.');

        self::notification(dedupKey: '');
    }

    public function testThrowsWhenSentWithoutTheTimeItWasSent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A sent notification must carry the time it was sent.');

        self::notification(status: NotificationStatus::SENT);
    }

    public function testThrowsWhenFailedWithoutAReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A failed notification must carry the failure reason.');

        self::notification(status: NotificationStatus::FAILED);
    }

    public function testRebuildsAnAlreadySettledNotification(): void
    {
        $sentAt = new DateTimeImmutable('2026-07-15 10:05:00');

        $notification = self::notification(status: NotificationStatus::SENT, sentAt: $sentAt);

        self::assertSame(NotificationStatus::SENT, $notification->status());
        self::assertSame($sentAt, $notification->sentAt());
    }

    public function testMarkSentRecordsTheDelivery(): void
    {
        $notification = self::notification();
        $sentAt = new DateTimeImmutable('2026-07-15 10:05:00');

        $notification->markSent($sentAt);

        self::assertSame(NotificationStatus::SENT, $notification->status());
        self::assertSame($sentAt, $notification->sentAt());
    }

    public function testMarkFailedRecordsTheReason(): void
    {
        $notification = self::notification();

        $notification->markFailed('SMTP connection refused');

        self::assertSame(NotificationStatus::FAILED, $notification->status());
        self::assertSame('SMTP connection refused', $notification->failureReason());
        self::assertNull($notification->sentAt());
    }

    public function testMarkFailedThrowsWhenReasonIsEmpty(): void
    {
        $notification = self::notification();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification failure reason cannot be empty.');

        $notification->markFailed('  ');
    }

    public function testFailedNotificationCanBeRetriedAndClearsTheReason(): void
    {
        $notification = self::notification();
        $notification->markFailed('SMTP connection refused');

        $notification->markSent(new DateTimeImmutable('2026-07-15 10:10:00'));

        self::assertSame(NotificationStatus::SENT, $notification->status());
        self::assertNull($notification->failureReason());
    }

    public function testCannotSendTheSameNotificationTwice(): void
    {
        $notification = self::notification();
        $notification->markSent(new DateTimeImmutable('2026-07-15 10:05:00'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Notification has already been sent.');

        $notification->markSent(new DateTimeImmutable('2026-07-15 10:06:00'));
    }

    public function testCannotFailAnAlreadySentNotification(): void
    {
        $notification = self::notification();
        $notification->markSent(new DateTimeImmutable('2026-07-15 10:05:00'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A sent notification cannot be marked as failed.');

        $notification->markFailed('late failure report');
    }

    private static function notification(
        string $id = 'n-0001',
        string $dedupKey = 'task_due:42:2026-07-16',
        NotificationStatus $status = NotificationStatus::PENDING,
        ?DateTimeImmutable $sentAt = null,
        ?string $failureReason = null,
    ): Notification {
        return new Notification(
            $id,
            NotificationType::TASK_DUE,
            Channel::EMAIL,
            ['taskId' => 42],
            $dedupKey,
            new DateTimeImmutable('2026-07-15 10:00:00'),
            $status,
            $sentAt,
            $failureReason,
        );
    }
}
