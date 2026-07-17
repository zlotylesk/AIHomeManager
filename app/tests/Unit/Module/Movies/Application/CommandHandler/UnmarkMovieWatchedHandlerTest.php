<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\UnmarkMovieWatched;
use App\Module\Movies\Application\CommandHandler\UnmarkMovieWatchedHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UnmarkMovieWatchedHandlerTest extends TestCase
{
    public function testUnmarksMovieWatched(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());
        $movie->markWatched();

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => !$m->isWatched() && null === $m->watchedAt()
        ));

        $handler = new UnmarkMovieWatchedHandler($repo);
        $handler(new UnmarkMovieWatched('m-1'));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new UnmarkMovieWatchedHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new UnmarkMovieWatched('missing'));
    }
}
