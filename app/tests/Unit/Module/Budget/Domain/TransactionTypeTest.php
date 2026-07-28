<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Domain;

use App\Module\Budget\Domain\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

/**
 * Pins the backing values of TransactionType — the stable
 * serialization/persistence contract the follow-up tasks rely on.
 */
final class TransactionTypeTest extends TestCase
{
    public function testBackingValues(): void
    {
        $values = [];
        foreach (TransactionType::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        self::assertSame(
            '{"INCOME":"income","EXPENSE":"expense"}',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
    }
}
