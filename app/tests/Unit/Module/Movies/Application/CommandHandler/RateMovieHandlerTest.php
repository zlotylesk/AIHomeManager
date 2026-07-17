<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\RateMovie;
use App\Module\Movies\Application\CommandHandler\RateMovieHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateMovieHandlerTest extends TestCase
{
    public function testSetsRating(): void
    {
        $movie = new Movie('m-1', new Title('Tenet'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => 8 === $m->userRating()?->value()
        ));

        $handler = new RateMovieHandler($repo);
        $handler(new RateMovie('m-1', 8));
    }

    public function testClearsRatingOnNull(): void
    {
        $movie = new Movie('m-1', new Title('Tenet'), new DateTimeImmutable());
        $movie->rate(new Rating(5));

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => null === $m->userRating()
        ));

        $handler = new RateMovieHandler($repo);
        $handler(new RateMovie('m-1', null));
    }

    public function testThrowsOnOutOfRangeWithoutSaving(): void
    {
        $movie = new Movie('m-1', new Title('Tenet'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::never())->method('save');

        $handler = new RateMovieHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new RateMovie('m-1', 11));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new RateMovieHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new RateMovie('missing', 5));
    }
}
