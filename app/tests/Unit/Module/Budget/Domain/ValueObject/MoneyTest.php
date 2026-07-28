<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Domain\ValueObject;

use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testConstructsWithAmountAndDefaultCurrency(): void
    {
        $money = new Money(1500);

        self::assertSame(1500, $money->amountInCents());
        self::assertSame('PLN', $money->currency());
    }

    public function testNormalizesCurrencyCase(): void
    {
        $money = new Money(1000, 'eur');

        self::assertSame('EUR', $money->currency());
    }

    public function testRejectsZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount must be greater than zero.');

        new Money(0);
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(-1);
    }

    public function testRejectsInvalidCurrencyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must be a 3-letter ISO 4217 code.');

        new Money(1000, 'zl');
    }

    public function testEqualsComparesAmountAndCurrency(): void
    {
        self::assertTrue(new Money(500, 'PLN')->equals(new Money(500, 'PLN')));
        self::assertFalse(new Money(500, 'PLN')->equals(new Money(600, 'PLN')));
        self::assertFalse(new Money(500, 'PLN')->equals(new Money(500, 'EUR')));
    }
}
