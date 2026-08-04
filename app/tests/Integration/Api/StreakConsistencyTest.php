<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-412: `/goals` and the `/` cockpit must show the same streak.
 *
 * A streak is a number the user compares between screens, so two different
 * values for the same activity discredit both. They came from two independent
 * sources — one computed live on every read, the other the `streaks` table that
 * only the nightly job writes — and nothing in the suite compared them, which is
 * why the divergence survived. These tests read both surfaces over HTTP with one
 * set of fixtures and assert they agree.
 */
final class StreakConsistencyTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'goals', 'streaks', 'book_reading_sessions', 'series_episodes',
            'articles', 'videos', 'music_listening_sessions',
            'tasks', 'article_daily_picks', 'series', 'books',
        ] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testBothSurfacesReportTheSameStreakForTodaysActivity(): void
    {
        $today = new DateTimeImmutable('today');
        $this->seedGoal();
        $this->seedReadingSession('sess-yesterday', $today->modify('-1 day'));
        $this->seedReadingSession('sess-today', $today);

        $goalsStreak = $this->goalsStreak();
        $cockpitGoal = $this->cockpitGoal();

        self::assertSame(2, $goalsStreak['currentLength']);
        self::assertSame($goalsStreak['currentLength'], $cockpitGoal['currentStreak']);
        self::assertSame($goalsStreak['longestLength'], $cockpitGoal['longestStreak']);
        self::assertSame(
            $goalsStreak['lastActivityDate'],
            // The cockpit serializes ISO-8601, /goals a bare date; comparing the
            // day is comparing the value, not the two formats.
            substr((string) $cockpitGoal['lastActivityDate'], 0, 10),
        );
        self::assertSame($today->format('Y-m-d'), $goalsStreak['lastActivityDate']);
    }

    public function testAGoalCreatedTodayWithActivityTodayIsNotAllZeroesInTheCockpit(): void
    {
        // The concrete symptom from the ticket: the cockpit's LEFT JOIN found no
        // `streaks` row until the 01:00 job had run, so a goal set up today read
        // as if nothing had been done — while /goals showed the activity.
        $this->seedGoal();
        $this->seedReadingSession('sess-today', new DateTimeImmutable('today'));

        $cockpitGoal = $this->cockpitGoal();

        self::assertSame(1, $cockpitGoal['currentStreak']);
        self::assertSame(1, $cockpitGoal['longestStreak']);
        self::assertNotNull($cockpitGoal['lastActivityDate']);
    }

    public function testTheAllTimeRecordSurvivesOnBothSurfacesEvenWithNoRecentActivity(): void
    {
        // The record can predate the 365-day read window, in which case the
        // stored row is the only thing that still knows it. Computing live
        // without merging it would quietly reset the user's best run to 0 —
        // and before this change /goals did exactly that, while the cockpit
        // reported it correctly. The two disagreed on `longest`, not only on
        // `current`, which the ticket does not mention.
        $this->seedGoal();
        $this->connection->insert('streaks', [
            'id' => 's-record', 'type' => 'book_pages',
            'current_length' => 4, 'longest_length' => 9,
            'last_activity_date' => '2024-01-05 00:00:00',
        ]);

        $goalsStreak = $this->goalsStreak();
        $cockpitGoal = $this->cockpitGoal();

        self::assertSame(9, $goalsStreak['longestLength']);
        self::assertSame(9, $cockpitGoal['longestStreak']);
        // The stored *current* run is not trusted: it is a nightly snapshot, and
        // no activity is in the window now, so the honest answer is 0 on both.
        self::assertSame(0, $goalsStreak['currentLength']);
        self::assertSame(0, $cockpitGoal['currentStreak']);
    }

    /** @return array<string, mixed> */
    private function goalsStreak(): array
    {
        $this->client->request('GET', '/api/goals/streaks');
        self::assertResponseIsSuccessful();

        $streaks = $this->jsonResponse($this->client);
        self::assertCount(1, $streaks);
        self::assertIsArray($streaks[0]);

        return $streaks[0];
    }

    /** @return array<string, mixed> */
    private function cockpitGoal(): array
    {
        $this->client->request('GET', '/api/dashboard');
        self::assertResponseIsSuccessful();

        $body = $this->jsonResponse($this->client);
        self::assertIsArray($body['goals']);
        self::assertCount(1, $body['goals']);
        self::assertIsArray($body['goals'][0]);

        return $body['goals'][0];
    }

    private function seedGoal(): void
    {
        $this->connection->insert('goals', [
            'id' => 'goal-consistency', 'type' => 'book_pages',
            'target_value' => 10, 'period' => 'daily',
        ]);
    }

    private function seedReadingSession(string $id, DateTimeImmutable $date): void
    {
        $this->connection->insert('book_reading_sessions', [
            'id' => $id, 'book_id' => 'book-1',
            'date' => $date->format('Y-m-d'), 'pages_read' => 5,
        ]);
    }
}
