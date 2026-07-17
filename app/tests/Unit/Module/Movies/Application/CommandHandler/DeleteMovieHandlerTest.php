<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\DeleteMovie;
use App\Module\Movies\Application\CommandHandler\DeleteMovieHandler;
use App\Module\Movies\Application\Exception\MovieNotFoundException;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DeleteMovieHandlerTest extends TestCase
{
    public function testRemovesMovie(): void
    {
        $movie = new Movie('m-1', new Title('Some Film'), new DateTimeImmutable());

        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn($movie);
        $repo->expects(self::once())->method('remove')->with($movie);

        $handler = new DeleteMovieHandler($repo);
        $handler(new DeleteMovie('m-1'));
    }

    public function testThrowsWhenMovieNotFound(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('remove');

        $handler = new DeleteMovieHandler($repo);

        $this->expectException(MovieNotFoundException::class);
        $handler(new DeleteMovie('missing'));
    }
}
