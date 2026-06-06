<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain\ValueObject;

use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChannelNameTest extends TestCase
{
    public function testAcceptsTypicalName(): void
    {
        $name = new ChannelName('Veritasium');

        self::assertSame('Veritasium', $name->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $name = new ChannelName("  Veritasium\n");

        self::assertSame('Veritasium', $name->value());
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChannelName('');
    }

    public function testRejectsWhitespaceOnlyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChannelName("   \t\n");
    }

    public function testRejectsTooLongValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChannelName(str_repeat('a', 256));
    }

    public function testEqualsReturnsTrueForSameName(): void
    {
        self::assertTrue(new ChannelName('Foo')->equals(new ChannelName('Foo')));
    }

    public function testEqualsReturnsFalseForDifferentName(): void
    {
        self::assertFalse(new ChannelName('Foo')->equals(new ChannelName('Bar')));
    }
}
