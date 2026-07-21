<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Application\Command\PollPodcastListens;
use App\Module\Podcasts\Application\Handler\PollPodcastListensHandler;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use App\Tests\Integration\Podcasts\Support\ProgrammableListeningHistory;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The whole sweep, end to end: the real poll handler dispatching onto the real
 * command bus into the real recording handler and real persistence — with only
 * the Spotify port replaced, because that is the one thing a test must not reach.
 *
 * This is the ticket's "brak duplikatów przy nakładających się oknach" proven
 * where it matters. The source reports state rather than events, so consecutive
 * polls legitimately re-report the same episodes; only the composition of the
 * two handlers plus the database constraint makes that harmless, and none of the
 * three can demonstrate it alone.
 *
 * The poll handler is invoked directly rather than dispatched, because
 * PollPodcastListens is routed to the async transport — dispatching it here
 * would park it in the in-memory transport and run nothing. That routing is
 * pinned separately by PollPodcastListensRoutingTest; everything downstream of
 * the poll stays fully wired.
 */
final class PodcastPollIdempotencyTest extends KernelTestCase
{
    private Connection $connection;
    private ProgrammableListeningHistory $source;
    private PollPodcastListensHandler $poll;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->source = new ProgrammableListeningHistory();

        // The source is the only substitution; the bus it dispatches onto is the
        // real one, so the recording handler and its persistence run for real.
        $this->poll = new PollPodcastListensHandler(
            $this->source,
            $container->get('command.bus'),
            new NullLogger(),
        );

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['podcast_listening_sessions', 'podcast_episodes', 'podcasts'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testOneSweepRecordsEveryReportedListen(): void
    {
        $this->sweepReturning([
            $this->listened('spotify-ep-1', 'Odcinek pierwszy'),
            $this->listened('spotify-ep-2', 'Odcinek drugi'),
        ]);

        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame(2, $this->rowCount('podcast_episodes'));
        self::assertSame(2, $this->rowCount('podcast_listening_sessions'));
    }

    /**
     * Two consecutive polls of the same half hour see exactly the same state —
     * the case the scheduler produces every thirty minutes forever.
     */
    public function testOverlappingWindowsCreateNoDuplicates(): void
    {
        $reported = [
            $this->listened('spotify-ep-1', 'Odcinek pierwszy'),
            $this->listened('spotify-ep-2', 'Odcinek drugi'),
        ];

        $this->sweepReturning($reported);
        $this->sweepReturning($reported);
        $this->sweepReturning($reported);

        self::assertSame(2, $this->rowCount('podcast_listening_sessions'), 'Three sweeps, two listens.');
        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame(2, $this->rowCount('podcast_episodes'));
    }

    /**
     * The listener kept going between polls: the same occurrence, further along.
     * That must move the existing record rather than add one.
     */
    public function testALaterSweepAdvancesTheSameSessionInsteadOfAddingOne(): void
    {
        $this->sweepReturning([
            $this->listened('spotify-ep-1', 'Odcinek pierwszy', new ListeningProgress(300_000, false)),
        ]);
        $this->sweepReturning([
            $this->listened('spotify-ep-1', 'Odcinek pierwszy', ListeningProgress::completed(1_750_000)),
        ]);

        self::assertSame(1, $this->rowCount('podcast_listening_sessions'));

        $row = $this->connection->fetchAssociative('SELECT * FROM podcast_listening_sessions');
        self::assertIsArray($row);
        self::assertSame(1_750_000, (int) $row['resume_position_ms']);
        self::assertSame(1, (int) $row['fully_played']);
    }

    /**
     * A user who never connected Spotify would otherwise DLQ one failure every
     * half hour forever; the sweep is expected to give up quietly and leave the
     * history untouched.
     */
    public function testAnUnreachableSourceLeavesTheHistoryUntouchedWithoutFailing(): void
    {
        $this->sweepReturning([$this->listened('spotify-ep-1', 'Odcinek pierwszy')]);

        $this->source->willFailWith('Spotify account not connected.');
        ($this->poll)(new PollPodcastListens());

        self::assertSame(1, $this->rowCount('podcast_listening_sessions'), 'The earlier listen survives.');
        self::assertSame(2, $this->source->calls, 'The sweep did run — it just found nothing to read.');
    }

    /**
     * @param list<ListenedEpisode> $listened
     */
    private function sweepReturning(array $listened): void
    {
        $this->source->willReturn($listened);
        ($this->poll)(new PollPodcastListens());
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function listened(
        string $episodeExternalId,
        string $episodeTitle,
        ?ListeningProgress $progress = null,
    ): ListenedEpisode {
        return new ListenedEpisode(
            'spotify-show-1',
            'Radio Nowak',
            $episodeExternalId,
            $episodeTitle,
            new DateTimeImmutable('2026-07-21 08:00:00'),
            $progress ?? new ListeningProgress(900_000, false),
            'Studio Nowak',
            'https://example.test/cover.jpg',
            new DateTimeImmutable('2026-07-01'),
            1_800_000,
        );
    }
}
