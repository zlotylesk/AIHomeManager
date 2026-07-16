<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Application;

use App\Module\Movies\Application\MovieMetadata;
use App\Module\Movies\Domain\Enum\MovieStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MovieMetadataTest extends TestCase
{
    public function testBuildsValidatedMetadata(): void
    {
        $m = MovieMetadata::fromRaw('https://example.com/poster.jpg', 1999, 'released', 'A film.');

        self::assertSame('https://example.com/poster.jpg', $m->coverUrl);
        self::assertSame(1999, $m->year);
        self::assertSame(MovieStatus::RELEASED, $m->status);
        self::assertSame('A film.', $m->description);
    }

    public function testAllNullsAreAllowed(): void
    {
        $m = MovieMetadata::fromRaw(null, null, null, null);

        self::assertNull($m->coverUrl);
        self::assertNull($m->year);
        self::assertNull($m->status);
        self::assertNull($m->description);
    }

    public function testTrimsCoverUrlThroughTheVo(): void
    {
        self::assertSame('https://example.com/p.jpg', MovieMetadata::fromRaw('  https://example.com/p.jpg  ', null, null, null)->coverUrl);
    }

    public function testRejectsInvalidCoverUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MovieMetadata::fromRaw('not-a-url', null, null, null);
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MovieMetadata::fromRaw(null, null, 'bogus', null);
    }

    public function testRejectsYearBelowRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MovieMetadata::fromRaw(null, 1000, null, null);
    }

    public function testRejectsYearAboveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MovieMetadata::fromRaw(null, 3000, null, null);
    }

    public function testAcceptsEarliestFilmYear(): void
    {
        self::assertSame(MovieMetadata::MIN_YEAR, MovieMetadata::fromRaw(null, MovieMetadata::MIN_YEAR, null, null)->year);
    }

    public function testRejectsOverlongDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MovieMetadata::fromRaw(null, null, null, str_repeat('x', MovieMetadata::MAX_DESCRIPTION_LENGTH + 1));
    }
}
