<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\MarkMovieWatched;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkMovieWatchedHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(MarkMovieWatched $command): void
    {
        $movie = $this->movies->findById($command->id);

        if (null === $movie) {
            throw new MovieNotFoundException(sprintf('Movie "%s" not found.', $command->id));
        }

        $movie->markWatched();

        $this->movies->save($movie);
    }
}
