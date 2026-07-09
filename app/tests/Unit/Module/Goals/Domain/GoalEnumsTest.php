<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Domain;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of the Goals enums — they are the stable
 * serialization/persistence contract the follow-up tasks rely on. The name→value
 * map is compared through its JSON encoding so the assertion stays a real
 * regression guard rather than a PHPStan-narrowed tautology.
 */
final class GoalEnumsTest extends TestCase
{
    public function testGoalTypeBackingValues(): void
    {
        $values = [];
        foreach (GoalType::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"BOOK_PAGES":"book_pages","SERIES_EPISODES":"series_episodes","ARTICLES_READ":"articles_read","MUSIC_ALBUMS":"music_albums","YOUTUBE_VIDEOS":"youtube_videos"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }

    public function testPeriodBackingValues(): void
    {
        $values = [];
        foreach (Period::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"DAILY":"daily","WEEKLY":"weekly","MONTHLY":"monthly"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }
}
