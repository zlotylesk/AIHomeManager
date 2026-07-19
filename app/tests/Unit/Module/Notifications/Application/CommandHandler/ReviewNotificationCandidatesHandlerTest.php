<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Notifications\Application\Command\ReviewNotificationCandidates;
use App\Module\Notifications\Application\CommandHandler\ReviewNotificationCandidatesHandler;
use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use App\Shared\Notification\NotificationRequest;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReviewNotificationCandidatesHandlerTest extends TestCase
{
    /** @var list<DispatchNotification> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];
    }

    public function testHandsEveryCandidateToTheDispatchEngine(): void
    {
        $this->handler([
            new NotificationRequest('task_due', 'task-1', '2026-07-19', ['title' => 'Czynsz']),
            new NotificationRequest('goal_streak_at_risk', 'streak-books', '2026-07-19'),
        ])(new ReviewNotificationCandidates());

        self::assertCount(2, $this->dispatched);
        self::assertSame('task_due', $this->dispatched[0]->type);
        self::assertSame('task-1', $this->dispatched[0]->subject);
        self::assertSame('Czynsz', $this->dispatched[0]->payload['title']);
        self::assertSame('goal_streak_at_risk', $this->dispatched[1]->type);
    }

    public function testAnEmptySweepDispatchesNothing(): void
    {
        $this->handler([])(new ReviewNotificationCandidates());

        self::assertSame([], $this->dispatched);
    }

    /**
     * One unreachable source must not cost the user every other reminder.
     */
    public function testAFailingSourceIsSkippedRatherThanAbortingTheSweep(): void
    {
        $candidates = $this->createStub(NotificationCandidateProviderInterface::class);
        $candidates->method('candidatesAt')->willThrowException(new RuntimeException('source is down'));

        new ReviewNotificationCandidatesHandler($candidates, $this->bus())(new ReviewNotificationCandidates());

        self::assertSame([], $this->dispatched);
    }

    /**
     * @param list<NotificationRequest> $candidates
     */
    private function handler(array $candidates): ReviewNotificationCandidatesHandler
    {
        $provider = $this->createStub(NotificationCandidateProviderInterface::class);
        $provider->method('candidatesAt')->willReturn($candidates);

        return new ReviewNotificationCandidatesHandler($provider, $this->bus());
    }

    private function bus(): MessageBusInterface&Stub
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            \assert($message instanceof DispatchNotification);
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }
}
