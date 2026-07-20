<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain\ValueObject;

use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListeningProgressTest extends TestCase
{
    public function testRejectsNegativePosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resume position must not be negative.');

        new ListeningProgress(-1, false);
    }

    public function testAcceptsZeroPosition(): void
    {
        self::assertSame(0, new ListeningProgress(0, false)->resumePositionMs());
    }

    public function testNotStartedIsNeitherPlayedNorPositioned(): void
    {
        $progress = ListeningProgress::notStarted();

        self::assertSame(0, $progress->resumePositionMs());
        self::assertFalse($progress->fullyPlayed());
        self::assertFalse($progress->isStarted());
    }

    public function testPartialPositionCountsAsStarted(): void
    {
        self::assertTrue(new ListeningProgress(1, false)->isStarted());
    }

    /**
     * The pair exists precisely for this case: an episode listened to the end and
     * then rewound reports position 0, and would look untouched if the flag were
     * dropped.
     */
    public function testCompletedAtPositionZeroStillCountsAsStarted(): void
    {
        $progress = ListeningProgress::completed();

        self::assertSame(0, $progress->resumePositionMs());
        self::assertTrue($progress->fullyPlayed());
        self::assertTrue($progress->isStarted());
    }

    public function testCompletedKeepsGivenPosition(): void
    {
        self::assertSame(3_600_000, ListeningProgress::completed(3_600_000)->resumePositionMs());
    }

    public function testEqualsComparesBothFacts(): void
    {
        self::assertTrue(new ListeningProgress(120, true)->equals(new ListeningProgress(120, true)));
        self::assertFalse(new ListeningProgress(120, true)->equals(new ListeningProgress(120, false)));
        self::assertFalse(new ListeningProgress(120, true)->equals(new ListeningProgress(121, true)));
    }
}
