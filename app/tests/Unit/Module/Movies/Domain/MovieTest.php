<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Domain;

use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Enum\MovieStatus;
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

    public function testNewMovieHasNoMetadata(): void
    {
        $movie = new Movie('m-0010', new Title('Heat'), new DateTimeImmutable());

        self::assertNull($movie->coverUrl());
        self::assertNull($movie->year());
        self::assertNull($movie->status());
        self::assertNull($movie->description());
    }

    public function testUpdateMetadataStoresValues(): void
    {
        $movie = new Movie('m-0011', new Title('Heat'), new DateTimeImmutable());

        $movie->updateMetadata('https://example.com/poster.jpg', 1995, MovieStatus::RELEASED, 'A heist film.');

        self::assertSame('https://example.com/poster.jpg', $movie->coverUrl());
        self::assertSame(1995, $movie->year());
        self::assertSame(MovieStatus::RELEASED, $movie->status());
        self::assertSame('A heist film.', $movie->description());
    }

    public function testUpdateMetadataWithNullsClears(): void
    {
        $movie = new Movie('m-0012', new Title('Heat'), new DateTimeImmutable());
        $movie->updateMetadata('https://example.com/poster.jpg', 1995, MovieStatus::RELEASED, 'A heist film.');

        $movie->updateMetadata(null, null, null, null);

        self::assertNull($movie->coverUrl());
        self::assertNull($movie->year());
        self::assertNull($movie->status());
        self::assertNull($movie->description());
    }

    public function testNewMovieHasNoTraktId(): void
    {
        $movie = new Movie('m-0013', new Title('Heat'), new DateTimeImmutable());

        self::assertNull($movie->traktId());
    }

    public function testLinkTraktStoresTheId(): void
    {
        $movie = new Movie('m-0014', new Title('Heat'), new DateTimeImmutable());

        $movie->linkTrakt('6');

        self::assertSame('6', $movie->traktId());
    }

    public function testLinkTraktRejectsEmptyId(): void
    {
        $movie = new Movie('m-0015', new Title('Heat'), new DateTimeImmutable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Trakt id cannot be empty.');

        $movie->linkTrakt('  ');
    }
}
