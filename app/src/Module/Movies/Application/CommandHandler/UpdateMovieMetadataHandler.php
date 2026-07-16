<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\UpdateMovieMetadata;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Application\MovieMetadata;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateMovieMetadataHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(UpdateMovieMetadata $command): void
    {
        $movie = $this->movies->findById($command->id);

        if (null === $movie) {
            throw new MovieNotFoundException(sprintf('Movie "%s" not found.', $command->id));
        }

        $metadata = MovieMetadata::fromRaw(
            $command->coverUrl,
            $command->year,
            $command->status,
            $command->description,
        );

        $movie->updateMetadata($metadata->coverUrl, $metadata->year, $metadata->status, $metadata->description);

        $this->movies->save($movie);
    }
}
