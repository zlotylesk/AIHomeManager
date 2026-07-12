<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Domain\Enum;

use App\Module\Search\Domain\Enum\SearchResultType;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of {@see SearchResultType} — the stable
 * serialization/persistence contract the search engine + REST API rely on. The
 * name→value map is compared through its JSON encoding so the assertion stays a
 * real regression guard rather than a PHPStan-narrowed tautology.
 */
final class SearchResultTypeTest extends TestCase
{
    public function testBackingValues(): void
    {
        $values = [];
        foreach (SearchResultType::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"ARTICLE":"article","BOOK":"book","SERIES":"series","MUSIC":"music","TASK":"task"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }
}
