<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Domain\ValueObject;

use App\Module\Articles\Domain\ValueObject\ArticleUrl;
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

    public function testThrowsOnMissingScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArticleUrl('example.com/article');
    }

    public function testThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArticleUrl('');
    }

    public function testThrowsOnPlainText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArticleUrl('not a url at all');
    }
}
