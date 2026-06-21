<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Domain\ValueObject;

use App\Module\Music\Domain\ValueObject\AlbumArtist;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AlbumArtistTest extends TestCase
{
    public function testAcceptsValidArtist(): void
    {
        $artist = new AlbumArtist('Radiohead');

        self::assertSame('Radiohead', $artist->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $artist = new AlbumArtist("  Pink Floyd \n");

        self::assertSame('Pink Floyd', $artist->value());
    }

    public function testAcceptsValueAtMaxLength(): void
    {
        $value = str_repeat('a', 255);

        self::assertSame($value, new AlbumArtist($value)->value());
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album artist must not be empty.');

        new AlbumArtist('');
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album artist must not be empty.');

        new AlbumArtist("   \t");
    }

    public function testRejectsValueExceedingMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not exceed 255 characters/');

        new AlbumArtist(str_repeat('a', 256));
    }

    public function testEqualsByValue(): void
    {
        $a = new AlbumArtist('The Cure');
        $b = new AlbumArtist('The Cure');
        $c = new AlbumArtist('The Smiths');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
