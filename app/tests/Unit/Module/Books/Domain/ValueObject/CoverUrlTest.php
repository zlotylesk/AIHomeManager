<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\ValueObject;

use App\Module\Books\Domain\ValueObject\CoverUrl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoverUrlTest extends TestCase
{
    public function testValidHttpsUrl(): void
    {
        $url = new CoverUrl('https://covers.openlibrary.org/b/id/12345-L.jpg');

        self::assertSame('https://covers.openlibrary.org/b/id/12345-L.jpg', $url->value());
    }

    public function testValidHttpUrl(): void
    {
        $url = new CoverUrl('http://example.com/cover.png');

        self::assertSame('http://example.com/cover.png', $url->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $url = new CoverUrl('  https://example.com/cover.jpg  ');

        self::assertSame('https://example.com/cover.jpg', $url->value());
    }

    public function testThrowsForEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('');
    }

    public function testThrowsForWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('   ');
    }

    public function testThrowsForMissingScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('covers.openlibrary.org/b/id/12345-L.jpg');
    }

    public function testThrowsForDisallowedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('javascript:alert(1)');
    }

    public function testThrowsForMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('https://');
    }

    public function testEqualsByValue(): void
    {
        $a = new CoverUrl('https://example.com/cover.jpg');
        $b = new CoverUrl('https://example.com/cover.jpg');
        $c = new CoverUrl('https://example.com/other.jpg');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
