<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\UpdateMovie;
use App\Module\Movies\Application\CommandHandler\UpdateMovieHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateMovieHandlerTest extends TestCase
{
    public function testRenamesMovie(): void
    {
        $movie = new Movie('m-1', new Title('Old Title'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => 'New Title' === $m->title()->value()
        ));

        $handler = new UpdateMovieHandler($repo);
        $handler(new UpdateMovie('m-1', 'New Title'));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateMovieHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new UpdateMovie('missing', 'New Title'));
    }

    public function testThrowsOnEmptyTitleWithoutSaving(): void
    {
        $movie = new Movie('m-1', new Title('Old Title'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::never())->method('save');

        $handler = new UpdateMovieHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateMovie('m-1', '   '));
    }
}
