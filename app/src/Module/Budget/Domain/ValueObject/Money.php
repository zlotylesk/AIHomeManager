<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\ValueObject;

use InvalidArgumentException;

/**
 * A monetary amount, stored as whole minor units (grosze/cents) rather than a
 * float — money that gets summed across a month of transactions (the report,
 * HMAI-380) cannot afford floating-point rounding drift.
 */
final readonly class Money
{
    public const string DEFAULT_CURRENCY = 'PLN';

    private int $amountInCents;

    private string $currency;

    public function __construct(int $amountInCents, string $currency = self::DEFAULT_CURRENCY)
    {
        if ($amountInCents <= 0) {
            throw new InvalidArgumentException('Money amount must be greater than zero.');
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if (1 !== preg_match('/^[A-Z]{3}$/', $normalizedCurrency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }

        $this->amountInCents = $amountInCents;
        $this->currency = $normalizedCurrency;
    }

    public function amountInCents(): int
    {
        return $this->amountInCents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amountInCents === $other->amountInCents
            && $this->currency === $other->currency;
    }
}
