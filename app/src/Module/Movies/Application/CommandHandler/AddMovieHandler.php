<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\AddMovie;
use App\Module\Movies\Application\MovieMetadata;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddMovieHandler
{
    public function __construct(private MovieRepositoryInterface $movies)
    {
    }

    public function __invoke(AddMovie $command): string
    {
        $movie = new Movie(
            id: Uuid::v4()->toRfc4122(),
            title: new Title($command->title),
            createdAt: new DateTimeImmutable(),
        );

        $metadata = MovieMetadata::fromRaw($command->coverUrl, $command->year, $command->status, $command->description);
        $movie->updateMetadata($metadata->coverUrl, $metadata->year, $metadata->status, $metadata->description);

        $this->movies->save($movie);

        return $movie->id();
    }
}
