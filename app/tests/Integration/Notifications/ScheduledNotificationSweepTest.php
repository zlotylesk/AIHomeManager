<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Infrastructure\Provider\DailyArticleCandidates;
use App\Module\Notifications\Infrastructure\Provider\DailyDigestCandidates;
use App\Module\Notifications\Infrastructure\Provider\StreakAtRiskCandidates;
use App\Module\Notifications\Infrastructure\Provider\UpcomingTaskCandidates;
use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers the scheduler rail against real tables, and — the point of the whole
 * two-rail design — that it shares one dedup identity with the reactive rail.
 */
final class ScheduledNotificationSweepTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);

        foreach (['tasks', 'streaks', 'article_daily_picks', 'articles'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
    }

    public function testFindsATaskScheduledForTodayThatNoEventWouldCatch(): void
    {
        $at = new DateTimeImmutable('2026-07-19 08:00:00');
        $this->givenTask('t-1', 'Zapłacić czynsz', '2026-07-19 18:00:00');

        $candidates = new UpcomingTaskCandidates($this->connection)->candidatesAt($at);

        self::assertCount(1, $candidates);
        self::assertSame('task_due', $candidates[0]->type);
        self::assertSame('task-t-1', $candidates[0]->subject);
        self::assertSame('2026-07-19', $candidates[0]->window);
        self::assertSame('Zapłacić czynsz', $candidates[0]->payload['title']);
    }

    /**
     * The reactive rail announces a task created today; the sweep sees the same
     * task later the same day. Both must describe the *same* occurrence, or the
     * engine's dedup could not collapse them and the user would be told twice.
     *
     * Unlike its neighbours this case cannot pin a fixed date: TaskCreated stamps
     * its own occurredAt from the real clock, and the trait only announces a task
     * landing on that very day. A hard-coded date would therefore pass on one
     * calendar day and fail from the next one on, so the fixture follows today.
     */
    public function testTheSweepAndTheEventAgreeOnTheOccurrenceIdentity(): void
    {
        $startsAt = new DateTimeImmutable('today 18:00');
        $this->givenTask('t-1', 'Zapłacić czynsz', $startsAt->format('Y-m-d H:i:s'));

        $fromSweep = new UpcomingTaskCandidates($this->connection)->candidatesAt($startsAt->setTime(20, 0))[0];

        $event = new TaskCreated('t-1', new TaskTitle('Zapłacić czynsz'), new TimeSlot($startsAt, $startsAt->modify('+1 hour')));
        $fromEvent = $event->toNotificationRequest();

        self::assertNotNull($fromEvent);
        self::assertSame($fromEvent->type, $fromSweep->type);
        self::assertSame($fromEvent->subject, $fromSweep->subject);
        self::assertSame($fromEvent->window, $fromSweep->window);
    }

    public function testACompletedTaskIsNotAnnounced(): void
    {
        $this->givenTask('t-1', 'Zrobione', '2026-07-19 18:00:00', 'completed');

        self::assertSame([], new UpcomingTaskCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00')));
    }

    public function testAStreakWithoutTodaysActivityIsAtRiskInTheEvening(): void
    {
        $this->givenStreak('books', 12, 30, '2026-07-18 21:00:00');

        $candidates = new StreakAtRiskCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 20:00:00'));

        self::assertCount(1, $candidates);
        self::assertSame('goal_streak_at_risk', $candidates[0]->type);
        self::assertSame('streak-books', $candidates[0]->subject);
        self::assertSame(12, $candidates[0]->payload['currentLength']);
    }

    /**
     * Warning at 08:00 that today has no activity yet would fire every single
     * morning and mean nothing.
     */
    public function testAStreakIsNotAtRiskInTheMorning(): void
    {
        $this->givenStreak('books', 12, 30, '2026-07-18 21:00:00');

        self::assertSame([], new StreakAtRiskCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00')));
    }

    public function testAStreakKeptAliveTodayIsNotAtRisk(): void
    {
        $this->givenStreak('books', 12, 30, '2026-07-19 09:00:00');

        self::assertSame([], new StreakAtRiskCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 20:00:00')));
    }

    public function testAnnouncesTheDaysUnreadArticlePick(): void
    {
        $this->givenArticlePick('a-1', 'Hexagonal architecture', 'https://example.com/hex', '2026-07-19 06:00:00');

        $candidates = new DailyArticleCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00'));

        self::assertCount(1, $candidates);
        self::assertSame('article_daily', $candidates[0]->type);
        self::assertSame('https://example.com/hex', $candidates[0]->payload['url']);
    }

    public function testAnAlreadyReadArticleNeedsNoAnnouncement(): void
    {
        $this->givenArticlePick('a-1', 'Hexagonal architecture', 'https://example.com/hex', '2026-07-19 06:00:00', isRead: true);

        self::assertSame([], new DailyArticleCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00')));
    }

    public function testTheDigestSummarisesTheDay(): void
    {
        $this->givenTask('t-1', 'Zapłacić czynsz', '2026-07-19 18:00:00');
        $this->givenArticlePick('a-1', 'Hexagonal architecture', 'https://example.com/hex', '2026-07-19 06:00:00');

        $candidates = new DailyDigestCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00'));

        self::assertCount(1, $candidates);
        self::assertSame('daily_digest', $candidates[0]->type);
        self::assertCount(2, $candidates[0]->payload['items']);
    }

    /**
     * A digest that arrives every morning to say "nothing" trains the user to
     * ignore digests.
     */
    public function testAnEmptyDayProducesNoDigest(): void
    {
        self::assertSame([], new DailyDigestCandidates($this->connection)->candidatesAt(new DateTimeImmutable('2026-07-19 08:00:00')));
    }

    private function givenTask(string $id, string $title, string $startsAt, string $status = 'pending'): void
    {
        $this->connection->insert('tasks', [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'time_start' => $startsAt,
            'time_end' => new DateTimeImmutable($startsAt)->modify('+1 hour')->format('Y-m-d H:i:s'),
        ]);
    }

    private function givenStreak(string $type, int $current, int $longest, ?string $lastActivity): void
    {
        $this->connection->insert('streaks', [
            'id' => 'streak-'.$type,
            'type' => $type,
            'current_length' => $current,
            'longest_length' => $longest,
            'last_activity_date' => $lastActivity,
        ]);
    }

    private function givenArticlePick(string $id, string $title, string $url, string $pickedAt, bool $isRead = false): void
    {
        $this->connection->insert('articles', [
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'category' => 'architecture',
            'estimated_read_time' => 8,
            'added_at' => $pickedAt,
            'is_read' => $isRead ? 1 : 0,
        ]);
        $this->connection->insert('article_daily_picks', [
            'id' => 'pick-'.$id,
            'article_id' => $id,
            'picked_at' => $pickedAt,
        ]);
    }
}
