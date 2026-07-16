<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\UpdateMovieMetadata;
use App\Module\Movies\Application\CommandHandler\UpdateMovieMetadataHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Enum\MovieStatus;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateMovieMetadataHandlerTest extends TestCase
{
    public function testUpdatesMetadata(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => 'https://example.com/p.jpg' === $m->coverUrl()
                && 1995 === $m->year()
                && MovieStatus::RELEASED === $m->status()
                && 'A heist film.' === $m->description()
        ));

        $handler = new UpdateMovieMetadataHandler($repo);
        $handler(new UpdateMovieMetadata('m-1', 'https://example.com/p.jpg', 1995, 'released', 'A heist film.'));
    }

    public function testClearsMetadataOnNulls(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());
        $movie->updateMetadata('https://example.com/p.jpg', 1995, MovieStatus::RELEASED, 'desc');

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => null === $m->coverUrl()
                && null === $m->year()
                && null === $m->status()
                && null === $m->description()
        ));

        $handler = new UpdateMovieMetadataHandler($repo);
        $handler(new UpdateMovieMetadata('m-1', null, null, null, null));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateMovieMetadataHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new UpdateMovieMetadata('missing', null, null, null, null));
    }

    public function testThrowsOnInvalidCoverUrlWithoutSaving(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateMovieMetadataHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateMovieMetadata('m-1', 'not-a-url', null, null, null));
    }

    public function testThrowsOnUnknownStatusWithoutSaving(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateMovieMetadataHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateMovieMetadata('m-1', null, null, 'bogus', null));
    }
}
