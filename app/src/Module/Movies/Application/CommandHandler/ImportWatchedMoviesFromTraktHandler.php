<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Application\Command\ImportWatchedMoviesFromTrakt;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Port\WatchedMoviesProviderInterface;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maps the user's watched movies from Trakt onto the Movie aggregate (HMAI-290),
 * the Movies counterpart of the Series watched-shows import.
 *
 * Idempotent by construction: movies are deduplicated on their Trakt id. A fresh
 * film is created (linked to its Trakt id) and marked watched with the real
 * last_watched_at; an existing-but-unwatched film is flipped watched; a re-run on
 * unchanged Trakt data writes nothing (save only when something changed). Chains
 * the ratings import at the end so one "Import from Trakt" does both.
 *
 * @phpstan-import-type WatchedMovie from WatchedMoviesProviderInterface
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ImportWatchedMoviesFromTraktHandler
{
    public function __construct(
        private WatchedMoviesProviderInterface $provider,
        private MovieRepositoryInterface $repository,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ImportWatchedMoviesFromTrakt $command): void
    {
        $movies = $this->provider->fetchWatchedMovies();

        $changed = 0;
        foreach ($movies as $movie) {
            if ($this->importMovie($movie)) {
                ++$changed;
            }
        }

        $this->logger->info('Trakt watched movies imported', [
            'movies' => \count($movies),
            'changed' => $changed,
        ]);

        $this->commandBus->dispatch(new ImportMovieRatingsFromTrakt());
    }

    /**
     * @param WatchedMovie $data
     *
     * @return bool whether the import created or updated anything (drives the save)
     */
    private function importMovie(array $data): bool
    {
        $traktId = (string) $data['traktId'];
        $movie = $this->repository->findByTraktId($traktId);
        $watchedAt = $this->parseWatchedAt($data['lastWatchedAt']);

        if (null === $movie) {
            $movie = new Movie(
                Uuid::v4()->toRfc4122(),
                new Title('' !== $data['title'] ? $data['title'] : 'Untitled'),
                new DateTimeImmutable(),
            );
            $movie->linkTrakt($traktId);
            if (null !== $data['year']) {
                $movie->updateMetadata(null, $data['year'], null, null);
            }
            $movie->markWatched($watchedAt);
            $this->repository->save($movie);

            return true;
        }

        if (!$movie->isWatched()) {
            $movie->markWatched($watchedAt);
            $this->repository->save($movie);

            return true;
        }

        return false;
    }

    private function parseWatchedAt(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
