<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Domain\ValueObject;

use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GoalTargetTest extends TestCase
{
    public function testExposesValue(): void
    {
        self::assertSame(42, new GoalTarget(42)->value());
    }

    public function testThrowsWhenZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Goal target must be a positive number.');

        new GoalTarget(0);
    }

    public function testThrowsWhenNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GoalTarget(-5);
    }

    public function testEqualsIsValueBased(): void
    {
        self::assertTrue(new GoalTarget(10)->equals(new GoalTarget(10)));
        self::assertFalse(new GoalTarget(10)->equals(new GoalTarget(11)));
    }
}
