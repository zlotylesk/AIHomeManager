<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain\ValueObject;

use App\Module\Series\Domain\ValueObject\AverageRating;
use App\Module\Series\Domain\ValueObject\Rating;
use PHPUnit\Framework\TestCase;

final class AverageRatingTest extends TestCase
{
    public function testCalculatesAverageFromCollection(): void
    {
        $average = new AverageRating([
            new Rating(8),
            new Rating(6),
            new Rating(7),
        ]);

        self::assertSame(7.0, $average->value());
    }

    public function testReturnsZeroForEmptyCollection(): void
    {
        $average = new AverageRating([]);

        self::assertSame(0.0, $average->value());
    }
}
