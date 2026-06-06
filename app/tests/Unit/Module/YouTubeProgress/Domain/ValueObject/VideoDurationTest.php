<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain\ValueObject;

use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoDurationTest extends TestCase
{
    public function testAcceptsZeroSeconds(): void
    {
        $duration = new VideoDuration(0);

        self::assertSame(0, $duration->toSeconds());
    }

    public function testRejectsNegativeSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VideoDuration(-1);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function isoDurationCases(): iterable
    {
        yield 'seconds only' => ['PT30S', 30];
        yield 'minutes only' => ['PT5M', 300];
        yield 'hours only' => ['PT1H', 3600];
        yield 'hours, minutes and seconds' => ['PT1H2M3S', 3723];
        yield 'minutes and seconds' => ['PT15M30S', 930];
        yield 'zero seconds' => ['PT0S', 0];
    }

    #[DataProvider('isoDurationCases')]
    public function testFromIsoDurationParsesYouTubeFormat(string $iso, int $expectedSeconds): void
    {
        $duration = VideoDuration::fromIsoDuration($iso);

        self::assertSame($expectedSeconds, $duration->toSeconds());
    }

    public function testFromIsoDurationRejectsMalformedInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VideoDuration::fromIsoDuration('1H2M3S');
    }

    public function testFromIsoDurationRejectsBarePtPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VideoDuration::fromIsoDuration('PT');
    }

    public function testFromIsoDurationRejectsLowercase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VideoDuration::fromIsoDuration('pt1h2m3s');
    }

    public function testEqualsReturnsTrueForSameSeconds(): void
    {
        self::assertTrue(new VideoDuration(60)->equals(new VideoDuration(60)));
    }

    public function testEqualsReturnsFalseForDifferentSeconds(): void
    {
        self::assertFalse(new VideoDuration(60)->equals(new VideoDuration(61)));
    }
}
