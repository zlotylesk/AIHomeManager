<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application;

use App\Module\Budget\Application\TransactionInput;
use App\Module\Budget\Domain\Enum\TransactionType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TransactionInputTest extends TestCase
{
    public function testBuildsValidatedInput(): void
    {
        $input = TransactionInput::fromRaw(4999, 'PLN', '2026-07-15', 'expense');

        self::assertSame(4999, $input->amount->amountInCents());
        self::assertSame('PLN', $input->amount->currency());
        self::assertSame('2026-07-15', $input->date->format('Y-m-d'));
        self::assertSame(TransactionType::EXPENSE, $input->type);
    }

    public function testRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransactionInput::fromRaw(1000, 'PLN', '2026-07-15', 'bogus');
    }

    public function testRejectsMalformedDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransactionInput::fromRaw(1000, 'PLN', 'not-a-date', 'expense');
    }

    public function testRejectsZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransactionInput::fromRaw(0, 'PLN', '2026-07-15', 'expense');
    }

    public function testDateHasNoTimeComponent(): void
    {
        $input = TransactionInput::fromRaw(1000, 'PLN', '2026-07-15', 'income');

        self::assertSame('00:00:00', $input->date->format('H:i:s'));
    }
}
