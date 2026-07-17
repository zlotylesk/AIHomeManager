<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\MarkMovieWatched;
use App\Module\Movies\Application\CommandHandler\MarkMovieWatchedHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MarkMovieWatchedHandlerTest extends TestCase
{
    public function testMarksMovieWatched(): void
    {
        $movie = new Movie('m-1', new Title('Heat'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => $m->isWatched() && $m->watchedAt() instanceof DateTimeImmutable
        ));

        $handler = new MarkMovieWatchedHandler($repo);
        $handler(new MarkMovieWatched('m-1'));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new MarkMovieWatchedHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new MarkMovieWatched('missing'));
    }
}
