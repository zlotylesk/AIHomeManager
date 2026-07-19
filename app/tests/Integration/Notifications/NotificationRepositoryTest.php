<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Infrastructure\Persistence\DoctrineNotificationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationRepositoryTest extends KernelTestCase
{
    private DoctrineNotificationRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineNotificationRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE notifications');
    }

    public function testRoundTripsAPendingNotification(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-19 08:15:00');
        $this->repository->save(new Notification(
            'n0000001-0000-0000-0000-000000000001',
            NotificationType::TASK_DUE,
            Channel::EMAIL,
            ['title' => 'Pay rent', 'dueAt' => '2026-07-16'],
            'task_due:task-42:2026-07-16:email',
            $createdAt,
        ));
        $this->em->clear();

        $found = $this->repository->findByDedupKey('task_due:task-42:2026-07-16:email');

        self::assertNotNull($found);
        self::assertSame(NotificationType::TASK_DUE, $found->type());
        self::assertSame(Channel::EMAIL, $found->channel());
        self::assertSame(NotificationStatus::PENDING, $found->status());
        // MySQL's JSON type normalizes object key order, so the payload round-trips
        // by content, not by insertion order — adapters must read keys by name.
        self::assertEquals(['title' => 'Pay rent', 'dueAt' => '2026-07-16'], $found->payload());
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $found->createdAt()->format('Y-m-d H:i:s'));
        self::assertNull($found->sentAt(), 'an undelivered notification must hydrate real nulls');
        self::assertNull($found->failureReason());
    }

    public function testPersistsTheSentTransition(): void
    {
        $this->repository->save($this->notification('n0000002-0000-0000-0000-000000000001', 'task_due:task-1:2026-07-16:email'));
        $this->em->clear();

        $loaded = $this->repository->findByDedupKey('task_due:task-1:2026-07-16:email');
        self::assertNotNull($loaded);
        $loaded->markSent(new DateTimeImmutable('2026-07-19 09:00:00'));
        $this->repository->save($loaded);
        $this->em->clear();

        $reloaded = $this->repository->findByDedupKey('task_due:task-1:2026-07-16:email');

        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::SENT, $reloaded->status());
        self::assertNotNull($reloaded->sentAt());
        self::assertSame('2026-07-19 09:00:00', $reloaded->sentAt()->format('Y-m-d H:i:s'));
    }

    public function testPersistsTheFailureReason(): void
    {
        $this->repository->save($this->notification('n0000003-0000-0000-0000-000000000001', 'task_due:task-2:2026-07-16:push'));
        $this->em->clear();

        $loaded = $this->repository->findByDedupKey('task_due:task-2:2026-07-16:push');
        self::assertNotNull($loaded);
        $loaded->markFailed('push endpoint gone');
        $this->repository->save($loaded);
        $this->em->clear();

        $reloaded = $this->repository->findByDedupKey('task_due:task-2:2026-07-16:push');

        self::assertNotNull($reloaded);
        self::assertSame(NotificationStatus::FAILED, $reloaded->status());
        self::assertSame('push endpoint gone', $reloaded->failureReason());
    }

    public function testFindByDedupKeyReturnsNullForAnUnannouncedOccurrence(): void
    {
        self::assertNull($this->repository->findByDedupKey('task_due:never:2026-07-16:email'));
    }

    public function testTheSameOccurrenceOnTwoChannelsCoexists(): void
    {
        $this->repository->save($this->notification('n0000004-0000-0000-0000-000000000001', 'task_due:task-9:2026-07-16:email'));
        $this->repository->save($this->notification('n0000004-0000-0000-0000-000000000002', 'task_due:task-9:2026-07-16:push', Channel::PUSH));
        $this->em->clear();

        self::assertNotNull($this->repository->findByDedupKey('task_due:task-9:2026-07-16:email'));
        self::assertNotNull($this->repository->findByDedupKey('task_due:task-9:2026-07-16:push'));
    }

    private function notification(string $id, string $dedupKey, Channel $channel = Channel::EMAIL): Notification
    {
        return new Notification(
            $id,
            NotificationType::TASK_DUE,
            $channel,
            [],
            $dedupKey,
            new DateTimeImmutable('2026-07-19 08:15:00'),
        );
    }
}
