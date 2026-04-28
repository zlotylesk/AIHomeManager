<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\ValueObject;

use App\Module\Books\Domain\ValueObject\ReadingProgress;
use PHPUnit\Framework\TestCase;

final class ReadingProgressTest extends TestCase
{
    public function testPercentageCalculation(): void
    {
        $progress = new ReadingProgress(50, 200);

        self::assertSame(25.0, $progress->percentage());
    }

    public function testPercentageForZeroPages(): void
    {
        $progress = new ReadingProgress(0, 200);

        self::assertSame(0.0, $progress->percentage());
    }

    public function testPercentageIsRoundedToOneDecimal(): void
    {
        $progress = new ReadingProgress(1, 3);

        self::assertSame(33.3, $progress->percentage());
    }

    public function testIsCompletedWhenAllPagesRead(): void
    {
        $progress = new ReadingProgress(200, 200);

        self::assertTrue($progress->isCompleted());
    }

    public function testIsNotCompletedWhenPagesRemaining(): void
    {
        $progress = new ReadingProgress(199, 200);

        self::assertFalse($progress->isCompleted());
    }

    public function testWithCurrentPageReturnsNewInstance(): void
    {
        $original = new ReadingProgress(50, 200);
        $updated = $original->withCurrentPage(100);

        self::assertSame(50, $original->currentPage());
        self::assertSame(100, $updated->currentPage());
        self::assertSame(200, $updated->totalPages());
    }

    public function testThrowsWhenCurrentPageExceedsTotalPages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReadingProgress(201, 200);
    }

    public function testThrowsWhenTotalPagesIsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReadingProgress(0, 0);
    }

    public function testThrowsWhenTotalPagesIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReadingProgress(0, -1);
    }

    public function testThrowsWhenCurrentPageIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReadingProgress(-1, 200);
    }
}
