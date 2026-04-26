<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Domain\Entity;

use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use PHPUnit\Framework\TestCase;

final class ArticleTest extends TestCase
{
    public function testGetters(): void
    {
        $addedAt = new \DateTimeImmutable('2022-01-01 12:00:00');
        $url = new ArticleUrl('https://example.com');

        $article = new Article(
            id: 'test-id',
            title: 'Test Article',
            url: $url,
            category: 'tech',
            estimatedReadTime: 5,
            addedAt: $addedAt,
            readAt: null,
            isRead: false,
        );

        self::assertSame('test-id', $article->id());
        self::assertSame('Test Article', $article->title());
        self::assertSame($url, $article->url());
        self::assertSame('tech', $article->category());
        self::assertSame(5, $article->estimatedReadTime());
        self::assertSame($addedAt, $article->addedAt());
        self::assertNull($article->readAt());
        self::assertFalse($article->isRead());
    }

    public function testReadArticleHasReadAt(): void
    {
        $addedAt = new \DateTimeImmutable('2022-01-01');
        $readAt = new \DateTimeImmutable('2022-01-15');

        $article = new Article(
            id: 'test-id',
            title: 'Read Article',
            url: new ArticleUrl('https://example.com'),
            category: null,
            estimatedReadTime: null,
            addedAt: $addedAt,
            readAt: $readAt,
            isRead: true,
        );

        self::assertTrue($article->isRead());
        self::assertSame($readAt, $article->readAt());
        self::assertNull($article->category());
        self::assertNull($article->estimatedReadTime());
    }
}
