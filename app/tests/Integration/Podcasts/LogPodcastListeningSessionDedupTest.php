<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drives the real recording path — the real command bus, the real Doctrine
 * repositories, real MySQL — stubbing nothing.
 *
 * The unit tests prove the same rules against in-memory doubles, which cannot
 * catch a mapping that hydrates wrong or a dedup key the database disagrees
 * with. This is the layer where "never log the same listen twice" is actually
 * guaranteed, and the poll re-reports every started episode on every run, so a
 * regression here would duplicate the entire history every thirty minutes.
 */
final class LogPodcastListeningSessionDedupTest extends KernelTestCase
{
    private MessageBusInterface $commandBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->commandBus = $container->get('command.bus');
        $this->connection = $container->get(Connection::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['podcast_listening_sessions', 'podcast_episodes', 'podcasts'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testAFirstListenMaterializesTheCatalogAndRecordsOneSession(): void
    {
        $this->log($this->listened());

        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame(1, $this->rowCount('podcast_episodes'));
        self::assertSame(1, $this->rowCount('podcast_listening_sessions'));

        $row = $this->onlySession();
        self::assertSame(900_000, (int) $row['resume_position_ms']);
        self::assertSame(0, (int) $row['fully_played']);

        $podcast = $this->connection->fetchAssociative('SELECT * FROM podcasts');
        self::assertIsArray($podcast);
        self::assertSame('spotify-show-1', $podcast['external_id']);
        self::assertSame('Radio Nowak', $podcast['title']);
    }

    /**
     * The poll re-reports every started episode on every run, so this is the
     * single most important guarantee in the module.
     */
    public function testRePollingTheSameListenNeverDuplicatesIt(): void
    {
        $listened = $this->listened();

        $this->log($listened);
        $this->log($listened);
        $this->log($listened);

        self::assertSame(1, $this->rowCount('podcast_listening_sessions'));
        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame(1, $this->rowCount('podcast_episodes'));
    }

    public function testAdvancedProgressFoldsIntoTheSameRow(): void
    {
        $this->log($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-21 08:00:00'),
            progress: new ListeningProgress(300_000, false),
        ));
        $this->log($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-21 20:00:00'),
            progress: ListeningProgress::completed(1_750_000),
        ));

        self::assertSame(1, $this->rowCount('podcast_listening_sessions'), 'One day, one session.');

        $row = $this->onlySession();
        self::assertSame(1_750_000, (int) $row['resume_position_ms']);
        self::assertSame(1, (int) $row['fully_played']);
    }

    /**
     * Restarting a finished episode drops the reported position back to near
     * zero; storing that would rewrite the day to look barely listened.
     */
    public function testARewindNeverRegressesTheStoredProgress(): void
    {
        $this->log($this->listened(progress: ListeningProgress::completed(1_750_000)));
        $this->log($this->listened(progress: new ListeningProgress(4_000, false)));

        $row = $this->onlySession();
        self::assertSame(1_750_000, (int) $row['resume_position_ms']);
        self::assertSame(1, (int) $row['fully_played'], 'Finished stays finished.');
    }

    public function testListeningAgainTheNextDayIsASecondSession(): void
    {
        $this->log($this->listened(listenedAt: new DateTimeImmutable('2026-07-21 20:00:00')));
        $this->log($this->listened(listenedAt: new DateTimeImmutable('2026-07-22 08:00:00')));

        self::assertSame(2, $this->rowCount('podcast_listening_sessions'));
        self::assertSame(1, $this->rowCount('podcast_episodes'), 'The same episode, on two days.');
    }

    /**
     * The same instant expressed in another timezone must not split into two
     * occurrences — the day bucket is taken in UTC.
     */
    public function testTheSameInstantInAnotherTimezoneIsOneOccurrence(): void
    {
        $this->log($this->listened(listenedAt: new DateTimeImmutable('2026-07-21 09:00:00 Europe/Warsaw')));
        $this->log($this->listened(listenedAt: new DateTimeImmutable('2026-07-21 07:00:00 UTC')));

        self::assertSame(1, $this->rowCount('podcast_listening_sessions'));
    }

    public function testASecondEpisodeReusesTheAlreadyKnownShow(): void
    {
        $this->log($this->listened());
        $this->log($this->listened(episodeExternalId: 'spotify-ep-2', episodeTitle: 'Odcinek drugi'));

        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame(2, $this->rowCount('podcast_episodes'));
        self::assertSame(2, $this->rowCount('podcast_listening_sessions'));

        $podcastIds = $this->connection->fetchFirstColumn('SELECT DISTINCT podcast_id FROM podcast_episodes');
        self::assertCount(1, $podcastIds, 'Both episodes hang off the same show.');
    }

    /**
     * The catalog mirrors the source, so a renamed publisher follows — without
     * the show being minted a second time under a fresh id.
     */
    public function testTheShowKeepsItsIdentityWhenItsMetadataChanges(): void
    {
        $this->log($this->listened());
        $originalId = (string) $this->connection->fetchOne('SELECT id FROM podcasts');

        $this->log($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-22 08:00:00'),
            publisher: 'Nowak Media',
        ));

        self::assertSame(1, $this->rowCount('podcasts'));
        self::assertSame($originalId, (string) $this->connection->fetchOne('SELECT id FROM podcasts'));
        self::assertSame('Nowak Media', (string) $this->connection->fetchOne('SELECT publisher FROM podcasts'));
    }

    private function log(ListenedEpisode $listened): void
    {
        $this->commandBus->dispatch(new LogPodcastListeningSession($listened));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlySession(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM podcast_listening_sessions');
        self::assertIsArray($row);

        return $row;
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function listened(
        ?DateTimeImmutable $listenedAt = null,
        ?ListeningProgress $progress = null,
        string $episodeExternalId = 'spotify-ep-1',
        string $episodeTitle = 'Odcinek pierwszy',
        ?string $publisher = 'Studio Nowak',
    ): ListenedEpisode {
        return new ListenedEpisode(
            'spotify-show-1',
            'Radio Nowak',
            $episodeExternalId,
            $episodeTitle,
            $listenedAt ?? new DateTimeImmutable('2026-07-21 08:00:00'),
            $progress ?? new ListeningProgress(900_000, false),
            $publisher,
            'https://example.test/cover.jpg',
            new DateTimeImmutable('2026-07-01'),
            1_800_000,
        );
    }
}
