<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Domain\ValueObject;

use App\Module\Music\Domain\ValueObject\AlbumTitle;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AlbumTitleTest extends TestCase
{
    public function testAcceptsValidTitle(): void
    {
        $title = new AlbumTitle('OK Computer');

        self::assertSame('OK Computer', $title->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $title = new AlbumTitle("  The Dark Side of the Moon \n");

        self::assertSame('The Dark Side of the Moon', $title->value());
    }

    public function testAcceptsValueAtMaxLength(): void
    {
        $value = str_repeat('a', 500);

        self::assertSame($value, new AlbumTitle($value)->value());
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title must not be empty.');

        new AlbumTitle('');
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Album title must not be empty.');

        new AlbumTitle("   \t");
    }

    public function testRejectsValueExceedingMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not exceed 500 characters/');

        new AlbumTitle(str_repeat('a', 501));
    }

    public function testEqualsByValue(): void
    {
        $a = new AlbumTitle('Kid A');
        $b = new AlbumTitle('Kid A');
        $c = new AlbumTitle('Amnesiac');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
