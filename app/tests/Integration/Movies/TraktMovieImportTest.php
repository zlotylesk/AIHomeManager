<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Application\Command\ImportWatchedMoviesFromTrakt;
use App\Module\Movies\Application\CommandHandler\ImportMovieRatingsFromTraktHandler;
use App\Module\Movies\Application\CommandHandler\ImportWatchedMoviesFromTraktHandler;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Port\MovieRatingsProviderInterface;
use App\Module\Movies\Domain\Port\WatchedMoviesProviderInterface;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Integration coverage for the Trakt → Movies import (HMAI-290) against a stubbed
 * Trakt provider: the real handlers wired to the real Doctrine repository + MySQL,
 * with no network. Pins the fresh-import mapping (Trakt-linked, marked watched with
 * the real last_watched_at + year + own rating), the skip-if-missing rating rule,
 * the flip of an existing-but-unwatched film, and idempotency (a re-run on unchanged
 * Trakt data creates no duplicates and leaves the stored state untouched).
 *
 * Only the Trakt HTTP boundary (the two Domain provider ports) and the chaining
 * command bus are stubbed; persistence goes through the real repository so the
 * mapping — the movie_rating DBAL type, the trakt_id unique index, watched_at — is
 * exercised, not mocked. The HTTP trigger (202/409) is covered by MoviesApiTest.
 */
final class TraktMovieImportTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private MovieRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->repository = self::getContainer()->get(MovieRepositoryInterface::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->executeStatement('TRUNCATE TABLE movies');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testFreshImportCreatesWatchedRatedTraktLinkedMovies(): void
    {
        $this->importMovies(
            watched: [
                ['traktId' => 101, 'title' => 'Inception', 'year' => 2010, 'lastWatchedAt' => '2026-01-02T20:00:00Z'],
                ['traktId' => 102, 'title' => 'Arrival', 'year' => 2016, 'lastWatchedAt' => '2026-03-04T18:30:00Z'],
            ],
            ratings: [
                ['traktId' => 101, 'rating' => 9],
                ['traktId' => 102, 'rating' => 8],
            ],
        );

        self::assertSame(2, $this->movieCount());

        $arrival = $this->movieByTraktId('102');
        self::assertSame('Arrival', $arrival['title']);
        self::assertSame(1, (int) $arrival['watched']);
        self::assertNotNull($arrival['watched_at']);
        self::assertStringContainsString('2026-03-04', (string) $arrival['watched_at']);
        self::assertSame(2016, (int) $arrival['year']);
        self::assertSame(8, (int) $arrival['user_rating']);
        self::assertSame('102', $arrival['trakt_id']);

        $inception = $this->movieByTraktId('101');
        self::assertSame(1, (int) $inception['watched']);
        self::assertStringContainsString('2026-01-02', (string) $inception['watched_at']);
        self::assertSame(9, (int) $inception['user_rating']);
    }

    public function testRatingForANonImportedMovieIsSkipped(): void
    {
        $this->importMovies(
            watched: [
                ['traktId' => 201, 'title' => 'Dune', 'year' => 2021, 'lastWatchedAt' => '2026-05-05T12:00:00Z'],
            ],
            ratings: [
                ['traktId' => 201, 'rating' => 7],
                ['traktId' => 999, 'rating' => 10],
            ],
        );

        self::assertSame(1, $this->movieCount());
        self::assertFalse($this->traktIdExists('999'));
        self::assertSame(7, (int) $this->movieByTraktId('201')['user_rating']);
    }

    public function testExistingUnwatchedMovieIsFlippedWatchedWithoutRename(): void
    {
        $existing = new Movie(Uuid::v4()->toRfc4122(), new Title('Tenet'), new DateTimeImmutable());
        $existing->linkTrakt('301');
        $this->repository->save($existing);
        $originalId = $existing->id();
        $this->entityManager->clear();

        $this->importMovies(
            watched: [
                ['traktId' => 301, 'title' => 'Tenet (Trakt title, must be ignored)', 'year' => 2020, 'lastWatchedAt' => '2026-06-06T10:00:00Z'],
            ],
            ratings: [],
        );

        self::assertSame(1, $this->movieCount());

        $tenet = $this->movieByTraktId('301');
        self::assertSame($originalId, $tenet['id'], 'the existing movie must be updated in place, not duplicated');
        self::assertSame('Tenet', $tenet['title'], 'an existing movie is not renamed by the import');
        self::assertSame(1, (int) $tenet['watched']);
        self::assertStringContainsString('2026-06-06', (string) $tenet['watched_at']);
    }

    public function testReimportOnUnchangedDataIsIdempotent(): void
    {
        $watched = [
            ['traktId' => 401, 'title' => 'Sicario', 'year' => 2015, 'lastWatchedAt' => '2026-02-02T21:00:00Z'],
            ['traktId' => 402, 'title' => 'Prisoners', 'year' => 2013, 'lastWatchedAt' => '2026-02-03T22:00:00Z'],
        ];
        $ratings = [
            ['traktId' => 401, 'rating' => 8],
            ['traktId' => 402, 'rating' => 9],
        ];

        $this->importMovies($watched, $ratings);
        $before = $this->stateSnapshot();

        // A second worker run sees a fresh entity manager (a new message = new request).
        $this->entityManager->clear();
        $this->importMovies($watched, $ratings);
        $after = $this->stateSnapshot();

        self::assertSame(2, $this->movieCount(), 'a re-run must not create duplicates (dedup by trakt_id)');
        self::assertSame($before, $after, 'a re-run on unchanged Trakt data must leave the stored state untouched');
    }

    /**
     * Runs the real import handlers against the real repository, stubbing only the
     * Trakt provider ports and the chaining bus (a no-op — the ratings handler is
     * invoked directly in the production order instead of via the async transport).
     *
     * @param list<array{traktId: int, title: string, year: int|null, lastWatchedAt: string|null}> $watched
     * @param list<array{traktId: int, rating: int}>                                               $ratings
     */
    private function importMovies(array $watched, array $ratings): void
    {
        $watchedProvider = $this->createStub(WatchedMoviesProviderInterface::class);
        $watchedProvider->method('fetchWatchedMovies')->willReturn($watched);

        $ratingsProvider = $this->createStub(MovieRatingsProviderInterface::class);
        $ratingsProvider->method('fetchMovieRatings')->willReturn($ratings);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new ImportMovieRatingsFromTrakt()));

        (new ImportWatchedMoviesFromTraktHandler($watchedProvider, $this->repository, $bus))(new ImportWatchedMoviesFromTrakt());
        (new ImportMovieRatingsFromTraktHandler($ratingsProvider, $this->repository))(new ImportMovieRatingsFromTrakt());
    }

    private function movieCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movies');
    }

    private function traktIdExists(string $traktId): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM movies WHERE trakt_id = :t', ['t' => $traktId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function movieByTraktId(string $traktId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, title, watched, watched_at, year, user_rating, trakt_id FROM movies WHERE trakt_id = :t',
            ['t' => $traktId],
        );

        self::assertIsArray($row, sprintf('no movie stored with trakt_id %s', $traktId));

        return $row;
    }

    /**
     * A stable, order-independent snapshot of the delivery-relevant columns, used to
     * assert a re-run changes nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function stateSnapshot(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT trakt_id, id, watched, watched_at, user_rating FROM movies ORDER BY trakt_id',
        );
    }
}
