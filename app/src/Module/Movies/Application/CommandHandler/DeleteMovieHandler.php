<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\DeleteMovie;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteMovieHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(DeleteMovie $command): void
    {
        $movie = $this->movies->findById($command->id);

        if (null === $movie) {
            throw new MovieNotFoundException(sprintf('Movie "%s" not found.', $command->id));
        }

        $this->movies->remove($movie);
    }
}
