<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\RateMovie;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Rating;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RateMovieHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(RateMovie $command): void
    {
        $movie = $this->movies->findById($command->id);

        if (null === $movie) {
            throw new MovieNotFoundException(sprintf('Movie "%s" not found.', $command->id));
        }

        $movie->rate(null === $command->rating ? null : new Rating($command->rating));

        $this->movies->save($movie);
    }
}
