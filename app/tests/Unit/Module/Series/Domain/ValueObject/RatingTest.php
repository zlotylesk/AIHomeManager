<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain\ValueObject;

use App\Module\Series\Domain\ValueObject\Rating;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RatingTest extends TestCase
{
    public function testCreatesWithValidValue(): void
    {
        $rating = new Rating(5);

        self::assertSame(5, $rating->value());
    }

    public function testThrowsOnTooLowValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Rating(0);
    }

    public function testThrowsOnTooHighValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Rating(11);
    }

    public function testCreatesWithMinimumBoundaryValue(): void
    {
        $rating = new Rating(1);

        self::assertSame(1, $rating->value());
    }

    public function testCreatesWithMaximumBoundaryValue(): void
    {
        $rating = new Rating(10);

        self::assertSame(10, $rating->value());
    }
}
