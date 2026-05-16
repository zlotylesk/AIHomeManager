<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Application\DTO;

use App\Module\Articles\Application\DTO\ArticleDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArticleDTOTest extends TestCase
{
    public function testFromRowMapsFullRow(): void
    {
        $dto = ArticleDTO::fromRow([
            'id' => 'a1',
            'title' => 'Hello',
            'url' => 'https://example.com',
            'category' => 'tech',
            'estimated_read_time' => '7',
            'added_at' => '2026-05-16 10:00:00',
            'read_at' => '2026-05-16 11:00:00',
            'is_read' => 1,
        ]);

        self::assertSame('a1', $dto->id);
        self::assertSame('Hello', $dto->title);
        self::assertSame('https://example.com', $dto->url);
        self::assertSame('tech', $dto->category);
        self::assertSame(7, $dto->estimatedReadTime);
        self::assertSame('2026-05-16 10:00:00', $dto->addedAt);
        self::assertSame('2026-05-16 11:00:00', $dto->readAt);
        self::assertTrue($dto->isRead);
    }

    public function testFromRowAllowsNullableColumnsToBeMissing(): void
    {
        $dto = ArticleDTO::fromRow([
            'id' => 'a1',
            'title' => 'Hello',
            'url' => 'https://example.com',
            'added_at' => '2026-05-16 10:00:00',
        ]);

        self::assertNull($dto->category);
        self::assertNull($dto->estimatedReadTime);
        self::assertNull($dto->readAt);
        self::assertFalse($dto->isRead);
    }

    public function testFromRowThrowsWhenRequiredColumnMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ArticleDTO::fromRow missing required column "url"');

        ArticleDTO::fromRow([
            'id' => 'a1',
            'title' => 'Hello',
            'added_at' => '2026-05-16 10:00:00',
        ]);
    }
}
