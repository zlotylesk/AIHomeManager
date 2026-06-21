<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\ValueObject;

use App\Module\Books\Domain\ValueObject\CoverUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverUrlTest extends TestCase
{
    public function testAcceptsHttpsUrl(): void
    {
        $url = new CoverUrl('https://covers.openlibrary.org/b/id/12345-L.jpg');

        self::assertSame('https://covers.openlibrary.org/b/id/12345-L.jpg', $url->value());
    }

    public function testAcceptsHttpUrl(): void
    {
        $url = new CoverUrl('http://example.com/cover.png');

        self::assertSame('http://example.com/cover.png', $url->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $url = new CoverUrl("  https://example.com/cover.jpg\n");

        self::assertSame('https://example.com/cover.jpg', $url->value());
    }

    public function testIsCaseInsensitiveOnScheme(): void
    {
        $url = new CoverUrl('HTTPS://example.com/cover.jpg');

        self::assertSame('HTTPS://example.com/cover.jpg', $url->value());
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cover URL cannot be empty.');

        new CoverUrl('');
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cover URL cannot be empty.');

        new CoverUrl("   \t\n");
    }

    public function testRejectsUrlWithoutScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid cover URL/');

        new CoverUrl('covers.openlibrary.org/b/id/12345-L.jpg');
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid cover URL/');

        new CoverUrl('not a url at all');
    }

    public function testRejectsSchemeWithoutHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid cover URL/');

        new CoverUrl('https://');
    }

    /** @return array<string, array{string}> */
    public static function maliciousSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data SVG' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/cover.jpg'],
            'gopher' => ['gopher://example.com/'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('maliciousSchemes')]
    public function testRejectsNonHttpScheme(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scheme must be http or https/');

        new CoverUrl($url);
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
