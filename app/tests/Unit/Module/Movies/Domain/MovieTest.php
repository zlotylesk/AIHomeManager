<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Domain;

use App\Module\Movies\Domain\Entity\Movie;
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
}
