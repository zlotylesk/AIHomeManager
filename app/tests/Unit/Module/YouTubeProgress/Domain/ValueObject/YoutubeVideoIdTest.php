<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain\ValueObject;

use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class YoutubeVideoIdTest extends TestCase
{
    public function testAcceptsTypicalYoutubeId(): void
    {
        $id = new YoutubeVideoId('dQw4w9WgXcQ');

        self::assertSame('dQw4w9WgXcQ', $id->value());
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new YoutubeVideoId('');
    }

    public function testRejectsTooLongValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new YoutubeVideoId(str_repeat('a', 21));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        self::assertTrue(new YoutubeVideoId('abc123')->equals(new YoutubeVideoId('abc123')));
    }

    public function testEqualsReturnsFalseForDifferentId(): void
    {
        self::assertFalse(new YoutubeVideoId('abc123')->equals(new YoutubeVideoId('xyz789')));
    }
}
