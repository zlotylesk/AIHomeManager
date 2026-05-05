<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Domain\ValueObject;

use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArticleUrlTest extends TestCase
{
    public function testCreatesWithValidHttpUrl(): void
    {
        $url = new ArticleUrl('https://example.com/article');

        self::assertSame('https://example.com/article', $url->value());
    }

    public function testCreatesWithUrlEncodedPath(): void
    {
        $url = new ArticleUrl('http://pl.wikipedia.org/wiki/Anio%C5%82_biznesu');

        self::assertSame('http://pl.wikipedia.org/wiki/Anio%C5%82_biznesu', $url->value());
    }

    public function testTrimsWhitespace(): void
    {
        $url = new ArticleUrl("  https://example.com/article\n");

        self::assertSame('https://example.com/article', $url->value());
    }

    public function testIsCaseInsensitiveOnScheme(): void
    {
        $url = new ArticleUrl('HTTPS://example.com/article');

        self::assertSame('HTTPS://example.com/article', $url->value());
    }

    public function testThrowsOnMissingScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ArticleUrl('example.com/article');
    }

    public function testThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Article URL cannot be empty.');

        new ArticleUrl('');
    }

    public function testThrowsOnWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Article URL cannot be empty.');

        new ArticleUrl("   \t\n");
    }

    public function testThrowsOnPlainText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ArticleUrl('not a url at all');
    }

    /** @return array<string, array{string}> */
    public static function maliciousSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript with comment' => ['javascript://example.com/%0Aalert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'data SVG' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/article'],
            'gopher' => ['gopher://example.com/'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('maliciousSchemes')]
    public function testRejectsNonHttpScheme(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scheme must be http or https/');

        new ArticleUrl($url);
    }
}
