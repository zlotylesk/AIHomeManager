<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Domain\ValueObject;

use App\Module\Movies\Domain\ValueObject\Rating;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RatingTest extends TestCase
{
    public function testAcceptsBoundaryValues(): void
    {
        self::assertSame(1, new Rating(1)->value());
        self::assertSame(10, new Rating(10)->value());
    }

    public function testRejectsValueBelowRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Rating(0);
    }

    public function testRejectsValueAboveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Rating(11);
    }

    public function testEqualsComparesByValue(): void
    {
        self::assertTrue(new Rating(7)->equals(new Rating(7)));
        self::assertFalse(new Rating(7)->equals(new Rating(8)));
    }
}
