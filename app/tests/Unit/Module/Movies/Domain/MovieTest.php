<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Domain;

use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MovieTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-15 10:00:00');

        $movie = new Movie('m-0001', new Title('Blade Runner 2049'), $createdAt);

        self::assertSame('m-0001', $movie->id());
        self::assertSame('Blade Runner 2049', $movie->title()->value());
        self::assertSame($createdAt, $movie->createdAt());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Movie id cannot be empty.');

        new Movie('', new Title('Arrival'), new DateTimeImmutable());
    }

    public function testThrowsWhenIdIsWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Movie("  \t", new Title('Arrival'), new DateTimeImmutable());
    }

    public function testRenameReplacesTitle(): void
    {
        $movie = new Movie('m-0002', new Title('Dune'), new DateTimeImmutable());

        $movie->rename(new Title('Dune: Part Two'));

        self::assertSame('Dune: Part Two', $movie->title()->value());
    }

    public function testRenameLeavesIdentityAndCreationTimeUntouched(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-15 10:00:00');
        $movie = new Movie('m-0003', new Title('Sicario'), $createdAt);

        $movie->rename(new Title('Sicario: Day of the Soldado'));

        self::assertSame('m-0003', $movie->id());
        self::assertSame($createdAt, $movie->createdAt());
    }

    public function testNewMovieIsNotWatchedAndUnrated(): void
    {
        $movie = new Movie('m-0004', new Title('Heat'), new DateTimeImmutable());

        self::assertFalse($movie->isWatched());
        self::assertNull($movie->watchedAt());
        self::assertNull($movie->userRating());
    }

    public function testMarkWatchedStampsProvidedTime(): void
    {
        $movie = new Movie('m-0005', new Title('Heat'), new DateTimeImmutable());
        $watchedAt = new DateTimeImmutable('2026-07-10 21:00:00');

        $movie->markWatched($watchedAt);

        self::assertTrue($movie->isWatched());
        self::assertSame($watchedAt, $movie->watchedAt());
    }

    public function testMarkWatchedDefaultsToNow(): void
    {
        $movie = new Movie('m-0006', new Title('Heat'), new DateTimeImmutable());

        $movie->markWatched();

        self::assertTrue($movie->isWatched());
        self::assertInstanceOf(DateTimeImmutable::class, $movie->watchedAt());
    }

    public function testUnmarkWatchedClearsFlagAndTime(): void
    {
        $movie = new Movie('m-0007', new Title('Heat'), new DateTimeImmutable());
        $movie->markWatched();

        $movie->unmarkWatched();

        self::assertFalse($movie->isWatched());
        self::assertNull($movie->watchedAt());
    }

    public function testRateSetsUserRating(): void
    {
        $movie = new Movie('m-0008', new Title('Heat'), new DateTimeImmutable());

        $movie->rate(new Rating(9));

        self::assertSame(9, $movie->userRating()?->value());
    }

    public function testRateNullClearsUserRating(): void
    {
        $movie = new Movie('m-0009', new Title('Heat'), new DateTimeImmutable());
        $movie->rate(new Rating(9));

        $movie->rate(null);

        self::assertNull($movie->userRating());
    }
}
