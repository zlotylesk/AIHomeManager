<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application\CommandHandler;

use App\Module\Movies\Application\Command\AddMovie;
use App\Module\Movies\Application\CommandHandler\AddMovieHandler;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Enum\MovieStatus;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddMovieHandlerTest extends TestCase
{
    public function testAddsMovieAndReturnsId(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => 'Blade Runner' === $m->title()->value()
        ));

        $handler = new AddMovieHandler($repo);
        $id = $handler(new AddMovie('Blade Runner'));

        self::assertNotEmpty($id);
    }

    public function testThrowsOnEmptyTitleWithoutSaving(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new AddMovieHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new AddMovie('   '));
    }

    public function testAddsMovieWithMetadata(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Movie $m): bool => 'Heat' === $m->title()->value()
                && 'https://example.com/poster.jpg' === $m->coverUrl()
                && 1995 === $m->year()
                && MovieStatus::RELEASED === $m->status()
                && 'A heist film.' === $m->description()
        ));

        $handler = new AddMovieHandler($repo);
        $id = $handler(new AddMovie('Heat', 'https://example.com/poster.jpg', 1995, 'released', 'A heist film.'));

        self::assertNotEmpty($id);
    }

    public function testThrowsOnInvalidMetadataWithoutSaving(): void
    {
        $repo = $this->createMock(MovieRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new AddMovieHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new AddMovie('Heat', 'not-a-url'));
    }
}
