<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain;

use App\Module\Books\Domain\ValueObject\CoverUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverUrlTest extends TestCase
{
    public function testAcceptsHttpUrl(): void
    {
        $url = new CoverUrl('http://example.com/cover.jpg');

        self::assertSame('http://example.com/cover.jpg', $url->value());
    }

    public function testAcceptsHttpsUrl(): void
    {
        $url = new CoverUrl('https://example.com/cover.jpg');

        self::assertSame('https://example.com/cover.jpg', $url->value());
    }

    public function testTrimsWhitespace(): void
    {
        $url = new CoverUrl("  https://example.com/cover.jpg\n");

        self::assertSame('https://example.com/cover.jpg', $url->value());
    }

    public function testIsCaseInsensitiveOnScheme(): void
    {
        $url = new CoverUrl('HTTPS://example.com/cover.jpg');

        self::assertSame('HTTPS://example.com/cover.jpg', $url->value());
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

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid cover URL/');

        new CoverUrl('not a url at all');
    }

    public function testRejectsUrlWithoutScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoverUrl('example.com/cover.jpg');
    }
}
