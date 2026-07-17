<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\UpdateMovie;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateMovieHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(UpdateMovie $command): void
    {
        $movie = $this->movies->findById($command->id);

        if (null === $movie) {
            throw new MovieNotFoundException(sprintf('Movie "%s" not found.', $command->id));
        }

        $movie->rename(new Title($command->title));

        $this->movies->save($movie);
    }
}
