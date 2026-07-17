<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Domain\Port\MovieRatingsProviderInterface;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Rating;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Maps the user's Trakt movie ratings (1–10) onto the Movie aggregate's own
 * rating (HMAI-290), chained after the watched-movies import.
 *
 * Skip-if-missing: a rating for a movie the watched import never materialised is
 * ignored — ratings only enrich films the user actually watched. Idempotent: a
 * rating equal to the stored one writes nothing, so a re-run on unchanged Trakt
 * data persists nothing.
 *
 * @phpstan-import-type MovieRating from MovieRatingsProviderInterface
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ImportMovieRatingsFromTraktHandler
{
    public function __construct(
        private MovieRatingsProviderInterface $provider,
        private MovieRepositoryInterface $repository,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ImportMovieRatingsFromTrakt $command): void
    {
        $ratings = $this->provider->fetchMovieRatings();

        $changed = 0;
        foreach ($ratings as $row) {
            if ($this->applyRating($row)) {
                ++$changed;
            }
        }

        $this->logger->info('Trakt movie ratings imported', [
            'ratings' => \count($ratings),
            'changed' => $changed,
        ]);
    }

    /**
     * @param MovieRating $row
     */
    private function applyRating(array $row): bool
    {
        $movie = $this->repository->findByTraktId((string) $row['traktId']);
        if (null === $movie) {
            return false;
        }

        $current = $movie->userRating();
        if (null !== $current && $current->value() === $row['rating']) {
            return false;
        }

        $movie->rate(new Rating($row['rating']));
        $this->repository->save($movie);

        return true;
    }
}
