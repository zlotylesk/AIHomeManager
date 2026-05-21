<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Domain\Entity;

use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArticleTest extends TestCase
{
    public function testGetters(): void
    {
        $addedAt = new DateTimeImmutable('2022-01-01 12:00:00');
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
        $addedAt = new DateTimeImmutable('2022-01-01');
        $readAt = new DateTimeImmutable('2022-01-15');

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
    }

    public function testMarkAsReadSetsIsReadAndReadAt(): void
    {
        $article = new Article(
            id: 'test-id',
            title: 'Unread Article',
            url: new ArticleUrl('https://example.com'),
            category: null,
            estimatedReadTime: null,
            addedAt: new DateTimeImmutable(),
            readAt: null,
            isRead: false,
        );

        $readAt = new DateTimeImmutable('2024-06-01 10:00:00');
        $article->markAsRead($readAt);

        self::assertTrue($article->isRead());
        self::assertSame($readAt, $article->readAt());
    }

    public function testUpdateMetadataChangesFields(): void
    {
        $article = new Article(
            id: 'test-id',
            title: 'Original Title',
            url: new ArticleUrl('https://example.com'),
            category: 'tech',
            estimatedReadTime: 5,
            addedAt: new DateTimeImmutable(),
            readAt: null,
            isRead: false,
        );

        $article->updateMetadata('New Title', 'news', 10);

        self::assertSame('New Title', $article->title());
        self::assertSame('news', $article->category());
        self::assertSame(10, $article->estimatedReadTime());
    }

    public function testUpdateMetadataCanClearOptionalFields(): void
    {
        $article = new Article(
            id: 'test-id',
            title: 'Title',
            url: new ArticleUrl('https://example.com'),
            category: 'tech',
            estimatedReadTime: 5,
            addedAt: new DateTimeImmutable(),
            readAt: null,
            isRead: false,
        );

        $article->updateMetadata('Title', null, null);

        self::assertNull($article->category());
        self::assertNull($article->estimatedReadTime());
    }

    public function testUpdateMetadataTrimsTitleAndCategory(): void
    {
        $article = $this->makeArticle();

        $article->updateMetadata('  Trimmed Title  ', "  tech\t", 5);

        self::assertSame('Trimmed Title', $article->title());
        self::assertSame('tech', $article->category());
    }

    public function testUpdateMetadataRejectsEmptyTitle(): void
    {
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Article test-id: title cannot be empty.');

        $article->updateMetadata('   ', 'tech', 5);
    }

    public function testUpdateMetadataRejectsTitleOverMaxLength(): void
    {
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title must be at most 500 characters');

        $article->updateMetadata(str_repeat('a', 501), null, null);
    }

    public function testUpdateMetadataRejectsBlankCategoryString(): void
    {
        // Blank-after-trim must throw — silent coercion to null would be a
        // ghost mutation. Caller has to pass null explicitly.
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('category cannot be a blank string');

        $article->updateMetadata('Title', '   ', null);
    }

    public function testUpdateMetadataRejectsCategoryOverMaxLength(): void
    {
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('category must be at most 255 characters');

        $article->updateMetadata('Title', str_repeat('a', 256), null);
    }

    public function testUpdateMetadataRejectsZeroEstimatedReadTime(): void
    {
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimatedReadTime must be a positive integer');

        $article->updateMetadata('Title', null, 0);
    }

    public function testUpdateMetadataRejectsNegativeEstimatedReadTime(): void
    {
        $article = $this->makeArticle();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimatedReadTime must be a positive integer');

        $article->updateMetadata('Title', null, -3);
    }

    public function testUpdateMetadataAcceptsExactlyMaxLengths(): void
    {
        // Boundary: 500 chars title and 255 chars category must pass. Off-by-one
        // regression guard for the inequality operator in the length check.
        $article = $this->makeArticle();

        $article->updateMetadata(str_repeat('a', 500), str_repeat('b', 255), 1);

        self::assertSame(500, mb_strlen($article->title()));
        self::assertSame(255, mb_strlen((string) $article->category()));
        self::assertSame(1, $article->estimatedReadTime());
    }

    private function makeArticle(): Article
    {
        return new Article(
            id: 'test-id',
            title: 'Original Title',
            url: new ArticleUrl('https://example.com'),
            category: 'tech',
            estimatedReadTime: 5,
            addedAt: new DateTimeImmutable(),
            readAt: null,
            isRead: false,
        );
    }
}
